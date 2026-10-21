<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\FleetAndDocuments\Application\DocumentAdministration;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Infrastructure\PrivateDocumentStorage;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Tests\PostgresTestCase;

class DocumentUploadConcurrencyTest extends PostgresTestCase
{
    private function isolated(callable $operation): void
    {
        $config = config('database.connections.pgsql_testing');
        $pdo = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'upload_concurrency_'.str_replace('-', '', (string) Str::uuid());
        $tables = ['users', 'roles', 'user_roles', 'fleet_assets', 'tractor_units', 'document_types', 'documents', 'document_configuration_snapshots', 'current_fleet_documents', 'document_upload_operations'];
        $pdo->exec('CREATE SCHEMA '.$schema);
        try {
            $pdo->exec('SET search_path TO '.$schema.',public');
            foreach ($tables as $table) {
                $pdo->exec('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING ALL)');
            }
            foreach ($tables as $table) {
                foreach ($pdo->query("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='public.$table'::regclass AND contype='f'")->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
                    $pdo->exec('ALTER TABLE '.$schema.'.'.$table.' ADD CONSTRAINT '.$constraint['conname'].' '.str_replace('public.', $schema.'.', $constraint['definition']));
                }
                foreach ($pdo->query("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgrelid='public.$table'::regclass AND NOT tgisinternal")->fetchAll(PDO::FETCH_COLUMN) as $trigger) {
                    $pdo->exec(str_replace(' ON public.'.$table.' ', ' ON '.$schema.'.'.$table.' ', $trigger));
                }
            }
            DB::statement('SET search_path TO '.$schema.',public');
            Storage::fake('fleet_documents');
            $actor = User::factory()->create(['is_active' => true]);
            $actor->roles()->attach(Role::firstOrCreate(['name' => 'administrator']));
            $asset = app(FleetAdministration::class)->save($actor, 'tractor_unit', ['code' => (string) Str::uuid()]);
            $type = DocumentType::create(['name' => 'Concurrent', 'tracks_expiration' => false, 'required_for_tractor_units' => false, 'required_for_tankers' => false]);
            DB::commit();
            $file = new UploadedFile(__DIR__.'/../Fixtures/fleet-documents/valid.pdf', 'document.pdf', null, null, true);
            $data = ['request_key' => (string) Str::uuid(), 'document_type_id' => $type->id];
            $operation($actor, $asset->id, $data, $file, $pdo, $schema);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::statement('SET search_path TO public');
            $pdo->exec('SET search_path TO public');
            $pdo->exec('DROP SCHEMA '.$schema.' CASCADE');
        }
    }

    public function test_two_independent_upload_requests_with_same_key_confirm_exactly_one_document(): void
    {
        $this->isolated(function (User $actor, string $asset, array $data, UploadedFile $file, PDO $pdo, string $schema): void {
            // Keep request A staged/uncommitted, let request B miss its receipt and wait
            // on the real application lock in a separate PHP process/connection/disk.
            $service = app(DocumentAdministration::class);
            DB::beginTransaction();
            $first = $service->register($actor, $asset, $data, $file);
            $directory = sys_get_temp_dir().'/upload-worker-'.Str::uuid();
            mkdir($directory, 0700);
            $input = $directory.'/input.json';
            file_put_contents($input, json_encode(['actor' => $actor->id, 'asset' => $asset, 'data' => $data, 'schema' => $schema, 'disk' => $directory.'/files'], JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, base_path('tests/Support/document-upload-worker.php'), $input], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/result', 'w'], 2 => ['file', $directory.'/error', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            try {
                $waiting = false;
                $deadline = microtime(true) + 60;
                while (microtime(true) < $deadline) {
                    $waiting = $pdo->query("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name='document-upload-worker' AND wait_event='advisory')")->fetchColumn();
                    if ($waiting) {
                        break;
                    }
                    usleep(10000);
                }
                $this->assertTrue((bool) $waiting, 'Second request must reach and wait on the database lock.');
                DB::commit();
                $this->assertSame(0, proc_close($process));
                $process = null;
                $this->assertSame($first->id, trim(file_get_contents($directory.'/result')));
                $this->assertSame(1, Document::count());
                $this->assertSame(1, DB::table('document_upload_operations')->count());
                $this->assertSame([], glob($directory.'/files/files/*') ?: []);
                $this->assertSame([], glob($directory.'/files/.pending/*.json') ?: []);
                // Dropped response: the exact committed operation is retried after reconnect.
                $this->assertSame($first->id, $service->register($actor, $asset, $data, $file)->id);
                Storage::disk('fleet_documents')->assertExists($first->storage_key);
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        });
    }

    public function test_failed_post_commit_cleanup_keeps_receipt_and_file_and_can_be_reconciled(): void
    {
        $this->isolated(function (User $actor, string $asset, array $data, UploadedFile $file): void {
            $disk = Storage::disk('fleet_documents');
            $fault = \Mockery::mock($disk)->makePartial();
            $fault->shouldReceive('delete')->once()->andReturn(false);
            Storage::set('fleet_documents', $fault);
            $service = app(DocumentAdministration::class);
            $first = $service->register($actor, $asset, $data, $file);
            $this->assertCount(1, $disk->files('.pending'));
            $this->assertSame($first->id, $service->register($actor, $asset, $data, $file)->id);
            $this->assertSame($first->id, $service->uploadResult($actor, $asset, $data['request_key'])->id);
            Storage::set('fleet_documents', $disk);
            $this->assertSame(1, app(PrivateDocumentStorage::class)->reconcile());
            $this->assertSame([], $disk->files('.pending'));
            $disk->assertExists($first->storage_key);
            $this->assertSame(1, Document::count());
        });
    }
}
