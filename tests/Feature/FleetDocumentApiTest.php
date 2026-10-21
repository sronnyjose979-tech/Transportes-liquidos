<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\FleetAndDocuments\Application\DocumentAdministration;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Domain\DocumentOperationFailed;
use App\Modules\FleetAndDocuments\Infrastructure\PrivateDocumentStorage;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\PostgresTestCase;

class FleetDocumentApiTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fleet_documents');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function account(string $role = 'administrator', bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function asset(string $kind = 'tractor_unit'): FleetAsset
    {
        $data = ['code' => (string) Str::uuid()];
        if ($kind === 'tanker') {
            $data += ['has_physical_partitions' => false, 'compartments' => [['code' => 'A', 'capacity_liters' => '100', 'is_active' => true]]];
        }

        return app(FleetAdministration::class)->save($this->account(), $kind, $data);
    }

    private function type(bool $expiration = true): DocumentType
    {
        return DocumentType::create(['name' => 'DEKRA', 'tracks_expiration' => $expiration,
            'required_for_tractor_units' => true, 'required_for_tankers' => true]);
    }

    private function file(string $extension = 'pdf'): UploadedFile
    {
        return new UploadedFile(__DIR__.'/../Fixtures/fleet-documents/valid.'.$extension, 'document.'.$extension, null, null, true);
    }

    private function upload(FleetAsset $asset, DocumentType $type, ?string $expiration = '2027-01-01', string $extension = 'pdf'): TestResponse
    {
        return $this->postJson('/api/fleet-assets/'.$asset->id.'/documents', [
            'request_key' => (string) Str::uuid(), 'document_type_id' => $type->id, 'expires_on' => $expiration, 'file' => $this->file($extension),
        ]);
    }

    public function test_administrator_configures_types_and_secretary_can_only_consult_them(): void
    {
        $this->actingAs($this->account());
        $payload = ['name' => 'Permiso', 'tracks_expiration' => true, 'required_for_tractor_units' => true, 'required_for_tankers' => false];
        $id = $this->postJson('/api/document-types', $payload)->assertCreated()->json('data.id');
        $this->patchJson('/api/document-types/'.$id, ['required_for_tankers' => true])->assertOk()->assertJsonPath('data.required_for_tankers', true);
        $this->actingAs($this->account('secretary'));
        $this->getJson('/api/document-types')->assertOk()->assertJsonCount(1, 'data')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/document-types', $payload)->assertForbidden();
        $this->patchJson('/api/document-types/'.$id, ['tracks_expiration' => false])->assertForbidden();
        $this->deleteJson('/api/document-types/'.$id)->assertStatus(405);
    }

    public function test_type_configuration_rejects_unapproved_fields_and_bad_input(): void
    {
        $this->actingAs($this->account());
        $this->postJson('/api/document-types', [])->assertUnprocessable();
        $this->postJson('/api/document-types', ['name' => ' ', 'tracks_expiration' => 'bad', 'required_for_tractor_units' => true, 'required_for_tankers' => false])->assertUnprocessable();
        $this->patchJson('/api/document-types/'.$this->type()->id, ['id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    public function test_real_pdf_jpg_and_png_are_private_and_downloadable_by_administrator_and_secretary(): void
    {
        foreach (['administrator', 'secretary'] as $role) {
            foreach (['pdf', 'jpg', 'png'] as $extension) {
                $this->actingAs($actor = $this->account($role));
                $asset = $this->asset($extension === 'png' ? 'tanker' : 'tractor_unit');
                $response = $this->upload($asset, $this->type(), extension: $extension)->assertCreated()
                    ->assertJsonPath('data.is_current', true)->assertJsonMissingPath('data.storage_key');
                $id = $response->json('data.id');
                $document = Document::findOrFail($id);
                $this->assertSame($actor->id, $document->uploaded_by_id);
                Storage::disk('fleet_documents')->assertExists($document->storage_key);
                $this->assertSame(file_get_contents($this->file($extension)->getRealPath()), Storage::disk('fleet_documents')->get($document->storage_key));
                $download = $this->get('/api/documents/'.$id.'/download')->assertOk()
                    ->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('document-'.$id.'.'.$extension);
                $this->assertSame(file_get_contents($this->file($extension)->getRealPath()), $download->streamedContent());
                $this->getJson('/api/documents/'.$id)->assertOk()->assertJsonMissingPath('data.storage_key');
                $this->get('/storage/'.$document->storage_key)->assertForbidden();
                $this->get('/storage/fleet-documents/'.$document->storage_key)->assertForbidden();
            }
        }
    }

    public function test_every_document_endpoint_requires_an_authorized_active_account(): void
    {
        $asset = $this->asset();
        $id = (string) Str::uuid();
        $operations = [
            ['GET', '/api/document-types'], ['GET', '/api/documents'],
            ['GET', '/api/documents/'.$id], ['GET', '/api/documents/'.$id.'/download'],
            ['GET', '/api/fleet-assets/'.$asset->id.'/documents'],
            ['GET', '/api/fleet-assets/'.$asset->id.'/documents/history'],
            ['GET', '/api/fleet-assets/'.$asset->id.'/documents/missing'],
            ['POST', '/api/fleet-assets/'.$asset->id.'/documents'],
            ['POST', '/api/fleet-assets/'.$asset->id.'/documents/'.$id.'/renew'],
        ];
        foreach ($operations as [$verb, $url]) {
            $this->json($verb, $url)->assertUnauthorized();
        }
        foreach (['driver', 'mechanic'] as $role) {
            $this->actingAs($this->account($role));
            foreach ($operations as [$verb, $url]) {
                $this->json($verb, $url)->assertForbidden();
            }
        }
        foreach ($operations as [$verb, $url]) {
            $this->actingAs($this->account('secretary', false))->json($verb, $url)->assertForbidden();
        }
        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('fleet_documents')->allFiles('files'));
    }

    public function test_upload_checks_content_mime_extension_and_exact_byte_limit(): void
    {
        $this->actingAs($this->account('secretary'));
        $asset = $this->asset();
        $type = $this->type();
        $invalid = [
            UploadedFile::fake()->createWithContent('bad.pdf', 'plain text'),
            UploadedFile::fake()->createWithContent('bad.jpg', file_get_contents($this->file()->getRealPath())),
            UploadedFile::fake()->createWithContent('bad.exe', file_get_contents($this->file()->getRealPath())),
            UploadedFile::fake()->createWithContent('bad.png', "\x89PNG\r\n\x1a\n"),
            UploadedFile::fake()->createWithContent('bad.pdf', "%PDF-1.4\ntruncated"),
            UploadedFile::fake()->createWithContent('bad.pdf', '%PDF-1.4'.str_repeat(' ', Document::MAX_FILE_SIZE_BYTES).'%%EOF'),
        ];
        foreach ($invalid as $file) {
            $this->postJson('/api/fleet-assets/'.$asset->id.'/documents', ['request_key' => (string) Str::uuid(), 'document_type_id' => $type->id, 'expires_on' => '2027-01-01', 'file' => $file])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $content = file_get_contents($this->file()->getRealPath());
        $content = substr_replace($content, str_repeat(' ', Document::MAX_FILE_SIZE_BYTES - strlen($content)).'%%EOF', strrpos($content, '%%EOF'), 5);
        $this->assertSame(Document::MAX_FILE_SIZE_BYTES, strlen($content));
        $file = UploadedFile::fake()->createWithContent('limit.pdf', $content);
        $this->postJson('/api/fleet-assets/'.$asset->id.'/documents', ['request_key' => (string) Str::uuid(), 'document_type_id' => $type->id, 'expires_on' => '2027-01-01', 'file' => $file])->assertCreated();
        $this->assertSame(1, Document::count());
    }

    public function test_upload_rejects_private_fields_bad_dates_and_missing_expiration(): void
    {
        $this->actingAs($this->account());
        $asset = $this->asset();
        $type = $this->type();
        $url = '/api/fleet-assets/'.$asset->id.'/documents';
        $base = ['request_key' => (string) Str::uuid(), 'document_type_id' => $type->id, 'file' => $this->file()];
        $this->postJson($url, $base)->assertUnprocessable()->assertJsonValidationErrors('expires_on');
        foreach (['storage_key', 'uploaded_by_id', 'registered_at', 'id', 'is_current'] as $field) {
            $this->postJson($url, $base + ['expires_on' => '2027-01-01', $field => '../secret'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($url, $base + ['issued_on' => '2027-02-01', 'expires_on' => '2027-01-01'])->assertUnprocessable();
        $this->postJson($url, $base + ['expires_on' => '2027-02-30'])->assertUnprocessable();
        $this->upload($asset, $this->type(false), null)->assertCreated()->assertJsonPath('data.validity', 'no_expiration');
        $this->assertSame(1, Document::count());
        $this->assertSame(1, count(Storage::disk('fleet_documents')->allFiles('files')));
    }

    public function test_renewal_preserves_history_and_frozen_configuration(): void
    {
        $this->actingAs($this->account('secretary'));
        $asset = $this->asset();
        $type = $this->type(false);
        $oldId = $this->upload($asset, $type, null)->assertCreated()->json('data.id');
        $old = Document::findOrFail($oldId);
        $original = $old->getRawOriginal();
        $this->actingAs($this->account());
        $this->patchJson('/api/document-types/'.$type->id, ['name' => 'Nueva configuración', 'tracks_expiration' => true, 'required_for_tractor_units' => false])->assertOk();
        $this->actingAs($this->account('secretary'));
        $this->getJson('/api/documents/'.$oldId)->assertOk()->assertJsonPath('data.validity', 'no_expiration')->assertJsonPath('data.configuration.name', 'DEKRA');
        $newId = $this->postJson('/api/fleet-assets/'.$asset->id.'/documents/'.$oldId.'/renew', ['request_key' => (string) Str::uuid(), 'file' => $this->file(), 'expires_on' => '2027-01-01'])
            ->assertCreated()->assertJsonPath('data.configuration.tracks_expiration', true)->json('data.id');
        $this->assertSame($original, $old->fresh()->getRawOriginal());
        $this->assertNotSame($old->storage_key, Document::findOrFail($newId)->storage_key);
        $this->getJson('/api/documents/'.$oldId)->assertOk()->assertJsonPath('data.is_current', false);
        $this->getJson('/api/fleet-assets/'.$asset->id.'/documents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $newId);
        $this->getJson('/api/fleet-assets/'.$asset->id.'/documents/history')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('current_fleet_documents', ['fleet_asset_id' => $asset->id, 'document_type_id' => $type->id, 'document_id' => $newId]);
        Storage::disk('fleet_documents')->assertExists($old->storage_key);
        $this->get('/api/documents/'.$oldId.'/download')->assertOk();
        $this->postJson('/api/fleet-assets/'.$this->asset()->id.'/documents/'.$oldId.'/renew', ['request_key' => (string) Str::uuid(), 'file' => $this->file(), 'expires_on' => '2027-01-01'])->assertNotFound();
        $this->deleteJson('/api/documents/'.$oldId)->assertStatus(405);
    }

    public function test_alerts_use_current_references_costa_rica_dates_and_current_required_types(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-12 05:59:59', 'UTC'));
        $this->actingAs($this->account('secretary'));
        $asset = $this->asset();
        $type = $this->type();
        $expired = $this->upload($asset, $type, '2026-10-10')->assertCreated()->json('data.id');
        $this->getJson('/api/documents?validity=expired')->assertOk()->assertJsonCount(1, 'data');
        $current = $this->upload($asset, $type, '2026-10-26')->assertCreated()->json('data.id');
        $this->getJson('/api/documents?validity=expired')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/documents?validity=expiring_soon')->assertOk()->assertJsonPath('data.0.id', $current);
        $this->getJson('/api/fleet-assets/'.$asset->id.'/documents/history?validity=expired')->assertOk()->assertJsonPath('data.0.id', $expired);
        $this->getJson('/api/fleet-assets/'.$asset->id.'/documents/missing')->assertOk()->assertJsonCount(0, 'data');
        $missing = $this->type(false);
        $this->getJson('/api/fleet-assets/'.$asset->id.'/documents/missing')->assertOk()->assertJsonPath('data.0.id', $missing->id);
        $this->getJson('/api/documents?validity=made_up')->assertUnprocessable();
        $this->getJson('/api/documents?per_page=101')->assertUnprocessable();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-27 06:00:00', 'UTC'));
        $this->getJson('/api/documents?validity=expired')->assertOk()->assertJsonPath('data.0.id', $current);
    }

    public function test_persistence_failure_compensates_new_file_and_preserves_current_history(): void
    {
        $this->actingAs($this->account());
        $asset = $this->asset();
        $type = $this->type();
        $oldId = $this->upload($asset, $type)->assertCreated()->json('data.id');
        $old = Document::findOrFail($oldId);
        $files = Storage::disk('fleet_documents')->allFiles('files');
        try {
            app(DocumentAdministration::class)->register($this->account(), $asset->id,
                ['request_key' => (string) Str::uuid(), 'document_type_id' => $type->id, 'issued_on' => '2028-01-01', 'expires_on' => '2027-01-01'], $this->file());
            $this->fail('La persistencia debe rechazar fechas inconsistentes.');
        } catch (ValidationException) {
            $this->assertSame($files, Storage::disk('fleet_documents')->allFiles('files'));
            $this->assertSame(1, Document::count());
            $this->assertDatabaseHas('current_fleet_documents', ['document_id' => $oldId]);
            Storage::disk('fleet_documents')->assertExists($old->storage_key);
        }
    }

    public function test_failed_partial_storage_is_compensated_without_registering_a_document(): void
    {
        $disk = Storage::disk('fleet_documents');
        $fault = \Mockery::mock($disk)->makePartial();
        $fault->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $file, $name) use ($disk) {
            $disk->put($directory.'/'.$name, 'partial');
            throw new RuntimeException('Simulated disk failure');
        });
        Storage::set('fleet_documents', $fault);
        try {
            app(DocumentAdministration::class)->register($this->account(), $this->asset()->id, ['request_key' => (string) Str::uuid(), 'document_type_id' => $this->type()->id, 'expires_on' => '2027-01-01'], $this->file());
            $this->fail('El almacenamiento debe fallar.');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(DocumentOperationFailed::class, $e);
            $this->assertSame('Simulated disk failure', $e->getPrevious()->getMessage());
            $this->assertSame(0, Document::count());
            $this->assertSame([], $disk->allFiles('files'));
            $this->assertSame([], $disk->files('.pending'));
        }
    }

    public function test_pending_cleanup_recovers_safely_and_preserves_historical_files(): void
    {
        $storage = app(PrivateDocumentStorage::class);
        $disk = $storage->disk();
        $this->actingAs($this->account());
        $oldId = $this->upload($this->asset(), $this->type())->assertCreated()->json('data.id');
        $old = Document::findOrFail($oldId);
        $orphan = (string) Str::uuid();
        $orphanKey = 'files/'.$orphan.'.pdf';
        $disk->put($orphanKey, 'uncommitted');
        $disk->put('.pending/'.$orphan.'.json', json_encode(['document_id' => $orphan, 'key' => $orphanKey]));
        $this->assertSame(1, $storage->reconcile());
        $disk->assertExists($old->storage_key);
        $disk->assertMissing($orphanKey);
        // A pending enclosing transaction must not be mistaken for an orphan.
        $this->assertSame(['.pending/'.$oldId.'.json'], $disk->files('.pending'));
        $this->assertSame(0, $storage->reconcile());
    }

    public function test_service_rechecks_privileges_after_upload_and_compensates_revocation(): void
    {
        $actor = $this->account('secretary');
        $asset = $this->asset();
        $type = $this->type();
        $disk = Storage::disk('fleet_documents');
        $fault = \Mockery::mock($disk)->makePartial();
        $fault->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $file, $name) use ($disk, $actor) {
            $path = $disk->putFileAs($directory, $file, $name);
            $actor->roles()->detach();

            return $path;
        });
        Storage::set('fleet_documents', $fault);
        try {
            app(DocumentAdministration::class)->register($actor, $asset->id, ['request_key' => (string) Str::uuid(), 'document_type_id' => $type->id, 'expires_on' => '2027-01-01'], $this->file());
            $this->fail('Una autorización anterior no permite registrar tras revocación.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Document::count());
            $this->assertSame([], $disk->allFiles('files'));
        }
    }

    public function test_document_writes_require_csrf_in_real_requests(): void
    {
        $this->actingAs($this->account());
        $asset = $this->asset();
        $this->app['env'] = 'local';
        $this->postJson('/api/document-types', [])->assertStatus(419);
        $this->patchJson('/api/document-types/'.$this->type()->id, [])->assertStatus(419);
        $this->postJson('/api/fleet-assets/'.$asset->id.'/documents', [])->assertStatus(419);
        $this->postJson('/api/fleet-assets/'.$asset->id.'/documents/'.Str::uuid().'/renew', [])->assertStatus(419);
    }

    public function test_database_preserves_snapshot_and_current_reference(): void
    {
        $this->actingAs($this->account());
        $id = $this->upload($this->asset(), $this->type())->assertCreated()->json('data.id');
        foreach ([
            fn () => DB::table('document_configuration_snapshots')->where('document_id', $id)->update(['tracks_expiration' => false]),
            fn () => DB::table('document_configuration_snapshots')->where('document_id', $id)->delete(),
            fn () => DB::table('current_fleet_documents')->where('document_id', $id)->update(['document_id' => (string) Str::uuid()]),
            fn () => DB::table('current_fleet_documents')->where('document_id', $id)->delete(),
            fn () => DB::statement('TRUNCATE current_fleet_documents'),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Debe protegerse la integridad documental.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertSame(1, DB::table('current_fleet_documents')->count());
    }

    public function test_failed_compensation_retains_a_receipt_and_later_reconciliation_removes_only_uncommitted_files(): void
    {
        $disk = Storage::disk('fleet_documents');
        $fault = \Mockery::mock($disk)->makePartial();
        $fault->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $file, $name) use ($disk) {
            $disk->put($directory.'/'.$name, 'partial');
            throw new RuntimeException('Write interrupted');
        });
        $fault->shouldReceive('delete')->once()->andReturn(false);
        Storage::set('fleet_documents', $fault);
        $this->actingAs($this->account());
        $asset = $this->asset();
        $type = $this->type();
        $response = $this->upload($asset, $type)->assertStatus(503);
        $this->assertStringNotContainsString('storage_key', $response->getContent());
        $this->assertStringNotContainsString('files/', $response->getContent());
        $this->assertStringNotContainsString('/workspace', $response->getContent());
        $this->assertSame(0, Document::count());
        $this->assertCount(1, $disk->files('.pending'));
        $this->assertCount(1, $disk->files('files'));
        Storage::set('fleet_documents', $disk);
        $this->artisan('fleet-documents:reconcile-storage')->assertExitCode(0);
        $this->assertSame([], $disk->files('files'));
        $this->assertSame([], $disk->files('.pending'));
    }

    public function test_reconciliation_rejects_traversal_and_does_not_delete_a_historical_file_when_its_receipt_cannot_close(): void
    {
        $storage = app(PrivateDocumentStorage::class);
        $disk = $storage->disk();
        $id = (string) Str::uuid();
        $disk->put('sentinel.pdf', 'keep');
        $marker = '.pending/'.$id.'.json';
        $disk->put($marker, json_encode(['document_id' => $id, 'key' => '../sentinel.pdf']));
        try {
            $storage->reconcile();
            $this->fail('No aceptar rutas arbitrarias en un registro de recuperación.');
        } catch (RuntimeException) {
            $disk->assertExists('sentinel.pdf');
            $disk->assertExists($marker);
        }
        $disk->delete($marker);
        $this->actingAs($this->account());
        $document = Document::findOrFail($this->upload($this->asset(), $this->type())->assertCreated()->json('data.id'));
        $disk->put('.pending/'.$document->id.'.json', json_encode(['document_id' => $document->id, 'key' => $document->storage_key]));
        $fault = \Mockery::mock($disk)->makePartial();
        $fault->shouldReceive('delete')->once()->andReturn(false);
        Storage::set('fleet_documents', $fault);
        try {
            $storage->reconcile();
            $this->fail('Debe informarse el fallo al cerrar un registro pendiente.');
        } catch (RuntimeException) {
            $disk->assertExists($document->storage_key);
            $disk->assertExists('.pending/'.$document->id.'.json');
        }
    }

    public function test_real_historical_download_is_denied_to_other_roles_and_missing_files_return_no_private_details(): void
    {
        $this->actingAs($this->account());
        $document = Document::findOrFail($this->upload($this->asset(), $this->type())->assertCreated()->json('data.id'));
        foreach (['driver', 'mechanic'] as $role) {
            $this->actingAs($this->account($role));
            $this->getJson('/api/documents/'.$document->id.'/download')->assertForbidden();
        }
        $this->actingAs($this->account('secretary'));
        Storage::disk('fleet_documents')->delete($document->storage_key);
        $response = $this->getJson('/api/documents/'.$document->id.'/download')->assertNotFound();
        $this->assertStringNotContainsString($document->storage_key, $response->getContent());
    }
}
