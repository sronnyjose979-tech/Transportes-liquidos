<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Workshop\Application\WorkshopAttachments;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use App\Modules\Workshop\Application\WorkshopCommands;
use App\Modules\Workshop\Infrastructure\PrivateWorkshopFiles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopAttachmentTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    private string $fileRoot;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        if (isset($this->fileRoot)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->fileRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->fileRoot);
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $this->prepareWorkshopSchema();
        $this->fileRoot = sys_get_temp_dir().'/workshop_files_'.Str::uuid();
        mkdir($this->fileRoot, 0700);
        config(['workshop.storage_root' => $this->fileRoot]);
        DB::beginTransaction();
        $actor = $this->actor();
        $asset = FleetAsset::create(['code' => 'FILES-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);
        $order = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Prueba', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => now(), 'created_by_id' => $actor->id]);
        DB::table('workshop_operations')->insert(['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => 'order_create', 'actor_id' => $actor->id, 'reason' => 'Prueba', 'occurred_at' => now(), 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'idempotency_scope' => 'order_create', 'request_fingerprint' => str_repeat('a', 64)]);
        $this->checkConstraints();
        DB::commit();
        $path = base_path('tests/Fixtures/fleet-documents/valid.png');
        $data = ['category' => 'photo', 'extension' => 'png', 'mime' => 'image/png', 'size_bytes' => filesize($path), 'checksum' => hash_file('sha256', $path), 'reason' => 'Evidencia sintética'];

        return [$actor, $order, $path, $data];
    }

    public function test_reservation_confirmation_replays_and_download_is_private(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $service = app(WorkshopAttachments::class);
        $key = (string) Str::uuid();
        $reservation = $service->reserve($actor, $order, $key, $data);
        $this->assertSame($reservation, $service->reserve($actor, $order, $key, $data));
        $confirm = (string) Str::uuid();
        $result = $service->confirm($actor, $reservation['reservation_id'], $confirm, $path);
        $this->assertSame($result, $service->confirm($actor, $reservation['reservation_id'], $confirm, $path));
        $this->assertSame(1, DB::table('workshop_attachments')->count());
        $response = $service->download($actor, $result['attachment_id']);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_release_recovers_partial_transfer_without_discarding_confirmed_evidence(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $service = app(WorkshopAttachments::class);
        $reservation = $service->reserve($actor, $order, (string) Str::uuid(), $data);
        mkdir($this->fileRoot.'/bytes', 0700);
        $staging = $this->fileRoot.'/bytes/'.$reservation['reservation_id'].'.png';
        file_put_contents($staging, 'partial');
        $key = (string) Str::uuid();
        $result = $service->release($actor, $reservation['reservation_id'], $key, 'Transferencia interrumpida');
        $this->assertSame($result, $service->release($actor, $reservation['reservation_id'], $key, 'Transferencia interrumpida'));
        $this->assertFileDoesNotExist($staging);
        $this->assertSame(0, DB::table('workshop_attachments')->count());
        $this->expectException(ValidationException::class);
        $service->confirm($actor, $reservation['reservation_id'], (string) Str::uuid(), $path);
    }

    public function test_twenty_open_reservations_count_towards_quota(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $service = app(WorkshopAttachments::class);
        for ($i = 0; $i < 20; $i++) {
            $service->reserve($actor, $order, (string) Str::uuid(), $data);
        }
        $this->expectException(ValidationException::class);
        $service->reserve($actor, $order, (string) Str::uuid(), $data);
    }

    public function test_owner_is_checked_before_bytes_and_driver_has_no_access(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $service = app(WorkshopAttachments::class);
        $reservation = $service->reserve($actor, $order, (string) Str::uuid(), $data);
        $other = $this->actor('secretary');
        try {
            $service->confirm($other, $reservation['reservation_id'], (string) Str::uuid(), $path);
            $this->fail('Debe exigir al cargador original.');
        } catch (AuthorizationException) {
            $this->assertFileDoesNotExist($this->fileRoot.'/bytes/'.$reservation['reservation_id'].'.png');
        }
        $driver = $this->actor('driver');
        $this->expectException(AuthorizationException::class);
        $service->listForOrder($driver, $order);
    }

    public function test_terminal_confirm_prevents_release_and_preserves_original_bytes(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $service = app(WorkshopAttachments::class);
        $reservation = $service->reserve($actor, $order, (string) Str::uuid(), $data);
        $service->confirm($actor, $reservation['reservation_id'], (string) Str::uuid(), $path);
        try {
            $service->release($actor, $reservation['reservation_id'], (string) Str::uuid(), 'Desistimiento tardío');
            $this->fail('No puede liberar evidencia confirmada.');
        } catch (ValidationException) {
            $this->assertSame(1, DB::table('workshop_operations')->where('reservation_operation_id', $reservation['reservation_id'])->count());
            $this->assertFileExists($this->fileRoot.'/bytes/'.$reservation['reservation_id'].'.png');
        }
    }

    public function test_corrupt_confirmed_file_is_incident_without_metadata_deletion(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $service = app(WorkshopAttachments::class);
        $reservation = $service->reserve($actor, $order, (string) Str::uuid(), $data);
        $result = $service->confirm($actor, $reservation['reservation_id'], (string) Str::uuid(), $path);
        file_put_contents($this->fileRoot.'/bytes/'.$reservation['reservation_id'].'.png', 'corrupt');
        try {
            $service->download($actor, $result['attachment_id']);
            $this->fail('No debe descargar contenido corrupto.');
        } catch (ValidationException) {
            $this->assertSame(1, DB::table('workshop_attachments')->count());
            $this->assertSame('attachment_confirm', $service->receipt($actor, $reservation['reservation_id'])['status']);
        }
    }

    public function test_two_connections_serialize_terminal_and_unique_index_rejects_second_terminal(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $service = app(WorkshopAttachments::class);
        $reservation = $service->reserve($actor, $order, (string) Str::uuid(), $data);
        $config = config('database.connections.pgsql_testing');
        $other = new \PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schema = DB::selectOne('SELECT current_schema() AS name')->name;
        $other->exec('SET search_path TO '.$schema.',public');
        $insert = function (\PDO $pdo) use ($actor, $order, $reservation): void {
            $pdo->prepare("INSERT INTO workshop_operations(id,work_order_id,operation_type,reservation_operation_id,actor_id,reason,occurred_at,request_key,request_fingerprint,idempotency_scope,after_values,confirmed_result) VALUES (?,?,'attachment_release',?,?,'Recuperación concurrente',clock_timestamp(),?,?,?,'{}','{}')")
                ->execute([(string) Str::uuid(), $order, $reservation['reservation_id'], $actor->id, (string) Str::uuid(), str_repeat('a', 64), 'attachment_release:'.$reservation['reservation_id']]);
        };
        DB::beginTransaction();
        $insert(DB::connection()->getPdo());
        $other->beginTransaction();
        $other->exec("SET LOCAL lock_timeout='100ms'");
        try {
            $insert($other);
            $this->fail('Segundo terminal debe esperar.');
        } catch (\PDOException $exception) {
            $this->assertSame('55P03', $exception->getCode());
            $other->rollBack();
        }
        DB::commit();
        $other->beginTransaction();
        try {
            $insert($other);
            $this->fail('Debe impedir segundo terminal.');
        } catch (\PDOException $exception) {
            $this->assertSame('23505', $exception->getCode());
            $other->rollBack();
        }
        $this->assertSame(1, DB::table('workshop_operations')->where('reservation_operation_id', $reservation['reservation_id'])->count());
    }

    public function test_revocation_during_transfer_blocks_confirmation_and_admin_can_recover(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $reservation = app(WorkshopAttachments::class)->reserve($actor, $order, (string) Str::uuid(), $data);
        $files = new class($actor->id) extends PrivateWorkshopFiles
        {
            public function __construct(private readonly int $actorId) {}

            public function write(string $source, string $key, array $expected): void
            {
                parent::write($source, $key, $expected);
                DB::table('users')->where('id', $this->actorId)->update(['is_active' => false]);
            }
        };
        $service = new WorkshopAttachments(app(WorkshopCommands::class), $files);
        try {
            $service->confirm($actor, $reservation['reservation_id'], (string) Str::uuid(), $path);
            $this->fail('Cuenta revocada no confirma.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('workshop_attachments')->count());
            $this->assertFileExists($this->fileRoot.'/bytes/'.$reservation['reservation_id'].'.png');
        }
        $admin = $this->actor();
        app(WorkshopAttachments::class)->release($admin, $reservation['reservation_id'], (string) Str::uuid(), 'Conciliación por revocación');
        $this->assertFileDoesNotExist($this->fileRoot.'/bytes/'.$reservation['reservation_id'].'.png');
    }

    public function test_historical_mechanic_reads_technical_evidence_without_financial_documents(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $service = app(WorkshopAttachments::class);
        $photo = $service->reserve($actor, $order, (string) Str::uuid(), $data);
        $photoResult = $service->confirm($actor, $photo['reservation_id'], (string) Str::uuid(), $path);
        $data['category'] = 'invoice';
        $invoice = $service->reserve($actor, $order, (string) Str::uuid(), $data);
        $invoiceResult = $service->confirm($actor, $invoice['reservation_id'], (string) Str::uuid(), $path);
        $mechanic = $this->actor('mechanic');
        DB::beginTransaction();
        $employee = Employee::create(['name' => 'Mecánico histórico sintético', 'user_id' => $mechanic->id, 'is_active' => true]);
        $assignment = (string) Str::uuid();
        DB::table('workshop_mechanic_assignments')->insert(['id' => $assignment, 'work_order_id' => $order, 'employee_id' => $employee->id, 'starts_at' => now()->subHours(2), 'assigned_by_id' => $actor->id, 'reason' => 'Prueba']);
        $commands = app(WorkshopCommands::class);
        $commands->record($actor, 'mechanic_assign:'.$order, (string) Str::uuid(), [], ['work_order_id' => $order, 'operation_type' => 'mechanic_assign', 'mechanic_assignment_id' => $assignment, 'reason' => 'Prueba']);
        $release = (string) Str::uuid();
        DB::table('workshop_mechanic_releases')->insert(['id' => $release, 'assignment_id' => $assignment, 'ends_at' => now()->subHour(), 'recorded_by_id' => $actor->id, 'reason' => 'Prueba']);
        $commands->record($actor, 'mechanic_release:'.$assignment, (string) Str::uuid(), [], ['work_order_id' => $order, 'operation_type' => 'mechanic_release', 'mechanic_assignment_id' => $assignment, 'mechanic_release_id' => $release, 'reason' => 'Prueba']);
        DB::commit();
        $visible = $service->listForOrder($mechanic, $order);
        $this->assertSame(1, $visible->total());
        $this->assertSame($photoResult['attachment_id'], $visible->items()[0]->id);
        $this->assertSame('nosniff', $service->download($mechanic, $photoResult['attachment_id'])->headers->get('X-Content-Type-Options'));
        $this->expectException(AuthorizationException::class);
        $service->download($mechanic, $invoiceResult['attachment_id']);
    }

    public function test_approved_evidence_revision_preserves_history_and_consumes_decision_once(): void
    {
        [$actor, $order, $path, $data] = $this->fixture();
        $service = app(WorkshopAttachments::class);
        $reservation = $service->reserve($actor, $order, (string) Str::uuid(), $data);
        $original = $service->confirm($actor, $reservation['reservation_id'], (string) Str::uuid(), $path);
        $before = json_decode(DB::selectOne('SELECT to_jsonb(a)::text AS snapshot FROM workshop_attachments a WHERE id=?', [$original['attachment_id']])->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $after = array_intersect_key($data, array_flip(['category', 'external_repair_id', 'mime', 'extension', 'size_bytes', 'checksum']));
        $after['category'] = 'document';
        $after['external_repair_id'] = null;
        $authorizations = app(WorkshopAuthorizations::class);
        $request = $authorizations->request($actor, (string) Str::uuid(), [
            'work_order_id' => $order, 'type' => 'rectification', 'reason' => 'Corregir clasificación',
            'target_version' => DB::table('workshop_work_orders')->where('id', $order)->value('version'),
            'content' => ['fact_table' => 'workshop_attachments', 'fact_id' => $original['attachment_id'], 'before' => $before, 'after' => $after, 'executor_id' => $actor->id],
        ]);
        $decision = $authorizations->decide($actor, $request['request_id'], (string) Str::uuid(), 'approved', 'Revisión autorizada');
        $revisionData = array_merge($data, ['category' => 'document', 'external_repair_id' => null, 'previous_attachment_id' => $original['attachment_id'], 'decision_id' => $decision['decision_id']]);
        $revisionReservation = $service->reserve($actor, $order, (string) Str::uuid(), $revisionData);
        $revision = $service->confirm($actor, $revisionReservation['reservation_id'], (string) Str::uuid(), $path);
        $this->assertSame(2, DB::table('workshop_attachments')->count());
        $row = DB::table('workshop_attachments')->where('id', $revision['attachment_id'])->first();
        $this->assertSame($original['attachment_id'], $row->previous_attachment_id);
        $this->assertSame(2, $row->revision);
        $this->assertSame(1, DB::table('workshop_authorization_applications')->where('decision_id', $decision['decision_id'])->count());
        $this->assertFileExists($this->fileRoot.'/bytes/'.$reservation['reservation_id'].'.png');
        $this->expectException(ValidationException::class);
        $service->reserve($actor, $order, (string) Str::uuid(), $revisionData);
    }
}
