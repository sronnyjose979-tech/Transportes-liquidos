<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class DocumentUploadIdempotencyTest extends PostgresTestCase
{
    private User $actor;

    private string $assetId;

    private string $typeId;

    private string $requestKey;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fleet_documents');
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->roles()->attach(Role::firstOrCreate(['name' => 'administrator']));
        $this->assetId = app(FleetAdministration::class)->save($this->actor, 'tractor_unit', ['code' => (string) Str::uuid()])->id;
        $this->typeId = DocumentType::create(['name' => 'Idempotency', 'tracks_expiration' => false, 'required_for_tractor_units' => false, 'required_for_tankers' => false])->id;
        $this->requestKey = (string) Str::uuid();
        $this->actingAs($this->actor);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['request_key' => $this->requestKey, 'document_type_id' => $this->typeId,
            'file' => new UploadedFile(__DIR__.'/../Fixtures/fleet-documents/valid.pdf', 'document.pdf', null, null, true)], $overrides);
    }

    private function url(): string
    {
        return '/api/fleet-assets/'.$this->assetId.'/documents';
    }

    private function receiptUrl(): string
    {
        return '/api/fleet-assets/'.$this->assetId.'/document-uploads/'.$this->requestKey;
    }

    public function test_replay_and_receipt_recover_lost_response_without_another_document_or_file(): void
    {
        $first = $this->postJson($this->url(), $this->data())->assertCreated();
        $this->postJson($this->url(), $this->data())->assertCreated()->assertExactJson($first->json());
        $this->getJson($this->receiptUrl())->assertOk()->assertExactJson($first->json());
        $fingerprint = DB::table('document_upload_operations')->value('fingerprint');
        $this->getJson($this->receiptUrl(), ['X-Upload-Fingerprint' => $fingerprint])->assertOk();
        $this->getJson($this->receiptUrl(), ['X-Upload-Fingerprint' => str_repeat('0', 64)])->assertConflict();
        $this->getJson($this->receiptUrl(), ['X-Upload-Fingerprint' => 'invalid'])->assertUnprocessable();
        $this->assertSame(1, Document::count());
        $this->assertSame(1, DB::table('document_upload_operations')->count());
        $this->assertCount(1, Storage::disk('fleet_documents')->files('files'));
        // A later version must not change the operation's identity.
        $this->postJson($this->url(), $this->data(['request_key' => (string) Str::uuid()]))->assertCreated();
        $this->postJson($this->url(), $this->data())->assertCreated()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.is_current', false);
    }

    public function test_same_key_rejects_different_bytes_dates_asset_type_and_renewal(): void
    {
        config(['app.debug' => true]);
        $id = $this->postJson($this->url(), $this->data())->assertCreated()->json('data.id');
        foreach ([['file' => UploadedFile::fake()->createWithContent('different.pdf', "%PDF-1.4\nchanged\n%%EOF")], ['issued_on' => '2026-01-01'], ['expires_on' => '2027-01-01']] as $change) {
            $this->postJson($this->url(), $this->data($change))->assertConflict()->assertExactJson(['message' => 'Clave reutilizada con contenido distinto. Consulte el resultado de la operación original.']);
        }
        $otherType = DocumentType::create(['name' => 'Other', 'tracks_expiration' => false, 'required_for_tractor_units' => false, 'required_for_tankers' => false]);
        $this->postJson($this->url(), $this->data(['document_type_id' => $otherType->id]))->assertConflict();
        $other = app(FleetAdministration::class)->save($this->actor, 'tractor_unit', ['code' => (string) Str::uuid()]);
        $this->postJson('/api/fleet-assets/'.$other->id.'/documents', $this->data())->assertConflict();
        $data = $this->data();
        unset($data['document_type_id']);
        $this->postJson($this->url().'/'.$id.'/renew', $data)->assertConflict();
        $data['request_key'] = (string) Str::uuid();
        $renewal = $this->postJson($this->url().'/'.$id.'/renew', $data)->assertCreated();
        $this->postJson($this->url().'/'.$id.'/renew', $data)->assertCreated()->assertExactJson($renewal->json());
        $this->assertSame(2, Document::count());
    }

    public function test_key_is_required_and_receipt_is_actor_asset_and_permission_scoped(): void
    {
        $data = $this->data();
        unset($data['request_key']);
        $this->postJson($this->url(), $data)->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->postJson($this->url(), $this->data())->assertCreated();
        $other = User::factory()->create(['is_active' => true]);
        $other->roles()->attach(Role::firstOrCreate(['name' => 'secretary']));
        $this->actingAs($other)->getJson($this->receiptUrl())->assertNotFound();
        $other->roles()->detach();
        $this->actingAs($other->fresh())->getJson($this->receiptUrl())->assertForbidden();
        $this->actor->roles()->detach();
        $this->actingAs($this->actor->fresh())->postJson($this->url(), $this->data())->assertForbidden();
        $this->getJson($this->receiptUrl())->assertForbidden();
        $this->assertSame(1, Document::count());
    }

    public function test_failure_before_commit_rolls_back_receipt_document_and_file_then_same_key_can_retry(): void
    {
        DB::unprepared("CREATE FUNCTION pg_temp.reject_upload_receipt() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic-token password=synthetic-secret' USING ERRCODE='XX000'; END; \$\$;
            CREATE TRIGGER reject_upload_receipt BEFORE INSERT ON document_upload_operations FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_upload_receipt()");
        $response = $this->postJson($this->url(), $this->data())->assertStatus(503);
        $this->assertStringNotContainsString('synthetic-secret', $response->getContent());
        $this->assertSame(0, Document::count());
        $this->assertSame(0, DB::table('document_upload_operations')->count());
        $this->assertSame(0, DB::table('document_configuration_snapshots')->count());
        $this->assertSame(0, DB::table('current_fleet_documents')->count());
        $this->assertSame([], Storage::disk('fleet_documents')->files('files'));
        DB::unprepared('DROP TRIGGER reject_upload_receipt ON document_upload_operations');
        $this->postJson($this->url(), $this->data())->assertCreated();
    }

    public function test_silently_incomplete_storage_never_commits_a_document_or_receipt(): void
    {
        $disk = Storage::disk('fleet_documents');
        $fault = \Mockery::mock($disk)->makePartial();
        $fault->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $file, $name) use ($disk): string {
            $key = $directory.'/'.$name;
            $disk->put($key, 'incomplete synthetic bytes');

            return $key;
        });
        Storage::set('fleet_documents', $fault);
        $this->postJson($this->url(), $this->data())->assertStatus(503);
        $this->assertSame(0, Document::count());
        $this->assertSame(0, DB::table('document_upload_operations')->count());
        $this->assertSame([], $disk->files('files'));
        $this->assertSame([], $disk->files('.pending'));
    }

    public function test_database_receipts_are_consistent_unique_and_immutable(): void
    {
        $id = $this->postJson($this->url(), $this->data())->assertCreated()->json('data.id');
        $other = User::factory()->create(['is_active' => true]);
        foreach ([
            ['23514', fn () => DB::table('document_upload_operations')->update(['fingerprint' => str_repeat('0', 64)])],
            ['23514', fn () => DB::table('document_upload_operations')->delete()],
            ['23514', fn () => DB::statement('TRUNCATE document_upload_operations')],
            ['23514', fn () => DB::table('document_upload_operations')->insert(['actor_id' => $other->id,
                'request_key' => (string) Str::uuid(), 'fleet_asset_id' => $this->assetId, 'document_id' => $id,
                'fingerprint' => str_repeat('0', 64)])],
            ['23505', fn () => DB::table('document_upload_operations')->insert(['actor_id' => $this->actor->id,
                'request_key' => (string) Str::uuid(), 'fleet_asset_id' => $this->assetId, 'document_id' => $id,
                'fingerprint' => str_repeat('0', 64)])],
        ] as [$state, $operation]) {
            try {
                DB::transaction($operation);
                $this->fail('Receipt invariants must be enforced by PostgreSQL.');
            } catch (QueryException $exception) {
                $this->assertSame($state, $exception->errorInfo[0]);
            }
        }
        $this->assertSame(1, DB::table('document_upload_operations')->count());
    }

    public function test_pending_directory_creation_is_inside_the_existing_storage_lock(): void
    {
        $disk = Storage::disk('fleet_documents');
        $fault = \Mockery::mock($disk)->makePartial();
        $fault->shouldReceive('makeDirectory')->with('.pending')->andReturnUsing(function () use ($disk): bool {
            $probe = fopen($disk->path('.coordination.lock'), 'c');
            try {
                $this->assertFalse(flock($probe, LOCK_EX | LOCK_NB), 'The coordinator must already own the lock before creating .pending.');
            } finally {
                fclose($probe);
            }

            return $disk->makeDirectory('.pending');
        });
        Storage::set('fleet_documents', $fault);
        $this->postJson($this->url(), $this->data())->assertCreated();
        $this->assertSame(1, Document::count());
    }
}
