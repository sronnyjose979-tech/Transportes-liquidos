<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Workshop\Infrastructure\PrivateWorkshopFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopAttachmentApiTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    private ?string $filesRoot = null;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        if ($this->filesRoot !== null) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->filesRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->filesRoot);
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $asset = DB::transaction(function () {
            $asset = FleetAsset::create(['code' => 'API-'.Str::uuid(), 'type' => 'tractor_unit']);
            TractorUnit::create(['fleet_asset_id' => $asset->id]);

            return $asset;
        });
        $order = (string) Str::uuid();
        DB::transaction(function () use ($admin, $asset, $order): void {
            DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Reparación', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => now(), 'created_by_id' => $admin->id]);
            DB::table('workshop_operations')->insert(['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => 'order_create', 'actor_id' => $admin->id, 'reason' => 'Prueba', 'occurred_at' => now(), 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'idempotency_scope' => 'order_create', 'request_fingerprint' => str_repeat('a', 64)]);
        });
        $this->filesRoot = sys_get_temp_dir().'/workshop_api_files_'.Str::uuid();
        mkdir($this->filesRoot, 0700);
        config(['workshop.storage_root' => $this->filesRoot]);
        $path = base_path('tests/Fixtures/fleet-documents/valid.png');
        $data = ['request_key' => (string) Str::uuid(), 'category' => 'photo', 'extension' => 'png', 'mime' => 'image/png', 'size_bytes' => filesize($path), 'checksum' => hash_file('sha256', $path), 'reason' => 'Imagen sintética'];

        return compact('admin', 'order', 'path', 'data');
    }

    public function test_private_upload_receipt_replay_download_and_list_hide_storage(): void
    {
        $f = $this->fixture();
        $url = '/api/workshop/orders/'.$f['order'].'/attachment-reservations';
        $this->postJson($url, $f['data'])->assertUnauthorized();
        $reservation = $this->actingAs($f['admin'])->postJson($url, $f['data'])->assertSuccessful()->json('data.reservation_id');
        $this->postJson($url, $f['data'])->assertSuccessful()->assertJsonPath('data.reservation_id', $reservation);
        $key = (string) Str::uuid();
        $attachment = $this->post('/api/workshop/attachment-reservations/'.$reservation.'/confirm', ['request_key' => $key, 'file' => new UploadedFile($f['path'], 'photo.png', 'image/png', null, true)], ['Accept' => 'application/json'])->assertSuccessful()->json('data.attachment_id');
        $this->postJson('/api/workshop/attachment-reservations/'.$reservation.'/confirm', ['request_key' => $key])->assertSuccessful()->assertJsonPath('data.attachment_id', $attachment);
        $this->getJson('/api/workshop/attachment-reservations/'.$reservation)->assertOk()->assertJsonPath('data.status', 'attachment_confirm');
        $list = $this->getJson('/api/workshop/orders/'.$f['order'].'/attachments')->assertOk();
        $this->assertArrayNotHasKey('storage_key', $list->json('data.0'));
        $this->get('/api/workshop/attachments/'.$attachment.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->actor('mechanic'))->getJson('/api/workshop/attachments/'.$attachment.'/download')->assertForbidden();
    }

    public function test_recovery_uses_only_private_verified_bytes_and_release_preserves_receipt(): void
    {
        $f = $this->fixture();
        $reservation = $this->actingAs($f['admin'])->postJson('/api/workshop/orders/'.$f['order'].'/attachment-reservations', $f['data'])->assertSuccessful()->json('data.reservation_id');
        $url = '/api/workshop/attachment-reservations/'.$reservation.'/confirm';
        $key = (string) Str::uuid();
        $this->postJson($url, ['request_key' => $key])->assertUnprocessable();
        $this->postJson($url, ['request_key' => $key, 'source' => $f['path']])->assertUnprocessable()->assertJsonValidationErrors('source');
        app(PrivateWorkshopFiles::class)->write($f['path'], $reservation.'.png', $f['data']);
        $this->postJson($url, ['request_key' => $key])->assertSuccessful();
        $second = $this->postJson('/api/workshop/orders/'.$f['order'].'/attachment-reservations', array_replace($f['data'], ['request_key' => (string) Str::uuid()]))->assertSuccessful()->json('data.reservation_id');
        $release = ['request_key' => (string) Str::uuid(), 'reason' => 'Carga abandonada'];
        $this->postJson('/api/workshop/attachment-reservations/'.$second.'/release', $release)->assertSuccessful()->assertJsonPath('data.released', true);
        $this->postJson('/api/workshop/attachment-reservations/'.$second.'/release', $release)->assertSuccessful();
        $this->getJson('/api/workshop/attachment-reservations/'.$second)->assertOk()->assertJsonPath('data.status', 'attachment_release');
    }

    public function test_reservations_count_toward_quota_and_other_users_cannot_confirm(): void
    {
        $f = $this->fixture();
        config(['workshop.attachment_limit' => 1]);
        $url = '/api/workshop/orders/'.$f['order'].'/attachment-reservations';
        $reservation = $this->actingAs($f['admin'])->postJson($url, $f['data'])->assertSuccessful()->json('data.reservation_id');
        $this->postJson($url, array_replace($f['data'], ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $other = $this->actor();
        $this->actingAs($other)->postJson('/api/workshop/attachment-reservations/'.$reservation.'/confirm', ['request_key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($this->actor('driver'))->getJson('/api/workshop/attachment-reservations/'.$reservation)->assertForbidden();
    }

    public function test_economic_evidence_cannot_be_delegated_to_a_mechanic(): void
    {
        $f = $this->fixture();
        $mechanic = $this->actor('mechanic');
        $employee = Employee::create(['name' => 'Mecánico sintético', 'user_id' => $mechanic->id, 'is_active' => true]);
        $this->actingAs($f['admin'])->postJson('/api/workshop/orders/'.$f['order'].'/mechanic-assignments', ['request_key' => (string) Str::uuid(), 'employee_id' => $employee->id, 'starts_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Prueba'])->assertCreated();
        $data = array_replace($f['data'], ['category' => 'invoice']);
        $reservation = $this->postJson('/api/workshop/orders/'.$f['order'].'/attachment-reservations', $data)->assertSuccessful()->json('data.reservation_id');
        $file = new UploadedFile($f['path'], 'invoice.png', 'image/png', null, true);
        $attachment = $this->post('/api/workshop/attachment-reservations/'.$reservation.'/confirm', ['request_key' => (string) Str::uuid(), 'file' => $file], ['Accept' => 'application/json'])->assertSuccessful()->json('data.attachment_id');
        $this->postJson('/api/workshop/facts/attachment/'.$attachment.'/rectifications', ['request_key' => (string) Str::uuid(), 'version' => 1, 'executor_id' => $mechanic->id, 'changes' => ['category' => 'photo'], 'reason' => 'Corrección'])->assertForbidden();
        $this->actingAs($mechanic)->getJson('/api/workshop/attachments/'.$attachment.'/download')->assertForbidden();
        $this->getJson('/api/workshop/orders/'.$f['order'].'/attachments')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, DB::table('workshop_authorization_requests')->count());
    }
}
