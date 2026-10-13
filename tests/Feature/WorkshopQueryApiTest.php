<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Workshop\Http\Controllers\WorkshopQueryController;
use App\Modules\Workshop\Http\Resources\WorkshopRecordResource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopQueryApiTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $this->prepareWorkshopSchema();
        Carbon::setTestNow('2026-10-12T12:00:00Z');
        $admin = $this->actor();
        $mechanic = $this->actor('mechanic');
        $employee = Employee::create(['name' => 'Mecánico sintético', 'user_id' => $mechanic->id, 'is_active' => true]);
        $asset = DB::transaction(function () {
            $asset = FleetAsset::create(['code' => 'QUERY-'.Str::uuid(), 'type' => 'tractor_unit']);
            TractorUnit::create(['fleet_asset_id' => $asset->id]);

            return $asset;
        });
        $this->actingAs($admin);
        $data = ['fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'priority' => 'normal', 'description' => 'Inspección', 'created_at' => now()->toIso8601String(), 'reason' => 'Prueba'];
        $order = $this->postJson('/api/workshop/orders', $data + ['request_key' => (string) Str::uuid()])->assertCreated()->json('data.order_id');
        $other = $this->postJson('/api/workshop/orders', $data + ['request_key' => (string) Str::uuid()])->assertCreated()->json('data.order_id');
        $assignment = $this->postJson('/api/workshop/orders/'.$order.'/mechanic-assignments', ['employee_id' => $employee->id, 'starts_at' => '2026-10-12T08:00:00Z', 'reason' => 'Prueba', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.assignment_id');
        $diagnosis = $this->postJson('/api/workshop/orders/'.$order.'/diagnoses', ['problem' => 'Falla', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Reparar', 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Prueba', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.diagnosis_id');
        $scope = $this->postJson('/api/workshop/orders/'.$order.'/scopes', ['diagnosis_id' => $diagnosis, 'description' => 'Alcance', 'tasks' => [['line_number' => 1, 'description' => 'Reparar']], 'reason' => 'Prueba', 'request_key' => (string) Str::uuid()])->assertSuccessful()->json('data');

        return compact('admin', 'mechanic', 'asset', 'order', 'other', 'assignment', 'diagnosis', 'scope');
    }

    public function test_every_read_collection_and_existing_record_has_json_pagination(): void
    {
        $f = $this->fixture();
        foreach (array_keys(WorkshopQueryController::RECORDS) as $kind) {
            $response = $this->getJson('/api/workshop/'.$kind.'?per_page=1')->assertOk()->assertJsonStructure(['data', 'links', 'meta'])->assertHeader('X-Content-Type-Options', 'nosniff');
            if ($response->json('data.0.id') !== null) {
                $this->getJson('/api/workshop/'.$kind.'/'.$response->json('data.0.id'))->assertOk()->assertJsonPath('data.id', $response->json('data.0.id'));
            }
        }
        $this->getJson('/api/workshop/orders/'.$f['order'].'/diagnoses')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/workshop/assets/'.$f['asset']->id.'/availability')->assertOk()->assertJsonPath('data.service_status', 'available');
    }

    public function test_mechanic_history_is_scoped_and_economic_requests_are_private(): void
    {
        $f = $this->fixture();
        $task = DB::table('workshop_scope_tasks')->where('scope_id', $f['scope']['scope_id'])->value('id');
        $cost = $this->postJson('/api/workshop/orders/'.$f['order'].'/costs', ['request_key' => (string) Str::uuid(), 'scope_task_id' => $task, 'cost_kind' => 'labor', 'basis' => 'estimated', 'amount' => '99999999999999.123456', 'currency' => 'CRC', 'description' => 'Estimación', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Prueba'])->assertSuccessful()->json('data.cost_record_id');
        $rectification = $this->postJson('/api/workshop/facts/cost/'.$cost.'/rectifications', ['request_key' => (string) Str::uuid(), 'version' => 2, 'executor_id' => $f['admin']->id, 'changes' => ['description' => 'Detalle'], 'reason' => 'Prueba'])->assertSuccessful()->json('data.request_id');
        $this->getJson('/api/workshop/authorization-requests/'.$rectification)->assertOk()->assertJsonPath('data.content.before.amount', '99999999999999.123456');
        $this->actingAs($f['mechanic'])->getJson('/api/workshop/orders')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $f['order']);
        $this->getJson('/api/workshop/orders/'.$f['order'].'/mechanic-assignments')->assertOk()
            ->assertJsonPath('data.0.is_current_actor', true)->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.0.employee_name', 'Mecánico sintético');
        $this->getJson('/api/workshop/orders/'.$f['other'])->assertForbidden();
        $this->getJson('/api/workshop/costs')->assertForbidden();
        $this->getJson('/api/workshop/authorization-requests/'.$rectification)->assertForbidden();
        $this->getJson('/api/workshop/authorization-requests')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($f['admin'])->postJson('/api/workshop/mechanic-assignments/'.$f['assignment'].'/release', ['request_key' => (string) Str::uuid(), 'ends_at' => '2026-10-12T12:00:00Z', 'reason' => 'Fin'])->assertCreated();
        $this->actingAs($f['mechanic'])->getJson('/api/workshop/orders/'.$f['order'].'/diagnoses')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/workshop/orders/'.$f['order'].'/mechanic-assignments')->assertOk()
            ->assertJsonPath('data.0.is_current_actor', true)->assertJsonPath('data.0.is_current', false);
        $this->postJson('/api/workshop/orders/'.$f['order'].'/time-events', ['request_key' => (string) Str::uuid(), 'mechanic_assignment_id' => $f['assignment'], 'event_type' => 'start', 'occurred_at' => '2026-10-12T12:00:00Z', 'reason' => 'Intento'])->assertForbidden();
    }

    public function test_filters_pending_status_dates_and_unknown_inputs(): void
    {
        $f = $this->fixture();
        $this->getJson('/api/workshop/orders?status=pending_authorization&per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/workshop/orders?from=2026-10-12T11:00:00Z&to=2026-10-12T13:00:00Z')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/workshop/orders?to=2026-10-12T13:00:00Z')->assertOk();
        $this->getJson('/api/workshop/authorization-requests?pending=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/workshop/scopes?effective=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/workshop/scopes/'.$f['scope']['scope_id'])->assertOk()->assertJsonPath('data.is_applied', false);
        foreach (['orders?per_page=101', 'orders?actor_id=1', 'diagnoses?status=closed', 'orders?to=2026-10-11T00:00:00Z&from=2026-10-12T00:00:00Z'] as $path) {
            $this->getJson('/api/workshop/'.$path)->assertUnprocessable();
        }
    }

    public function test_authentication_revocation_error_headers_and_csrf(): void
    {
        $this->prepareWorkshopSchema();
        $this->getJson('/api/workshop/orders')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->actor('driver'))->getJson('/api/workshop/orders')->assertForbidden();
        $this->actingAs($this->actor('administrator', false))->getJson('/api/workshop/orders')->assertForbidden();
        $admin = $this->actor();
        $this->actingAs($admin)->getJson('/api/workshop/orders/'.Str::uuid())->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
        $role = $admin->roles()->first();
        DB::table('user_roles')->where('user_id', $admin->id)->where('role_id', $role->id)->delete();
        $this->getJson('/api/workshop/orders')->assertForbidden();
        $this->actingAs($this->actor());
        $this->app['env'] = 'local';
        try {
            $this->postJson('/api/workshop/orders', [])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_resource_redacts_private_paths_and_preserves_exact_json_numbers(): void
    {
        $json = '{"before":{"amount":99999999999999.123456,"storage_key":"private/file.png","description":"value 123.456 and \\"amount\\":789.123"},"after":{"quantity":12.345678},"path":"private/root"}';
        $resource = new WorkshopRecordResource((object) ['id' => (string) Str::uuid(), 'content' => $json, 'storage_key' => 'private/file.png']);
        $data = $resource->resolve(Request::create('/api/workshop/orders'));
        $this->assertSame('99999999999999.123456', $data['content']['before']['amount']);
        $this->assertSame('12.345678', $data['content']['after']['quantity']);
        $this->assertArrayNotHasKey('storage_key', $data);
        $this->assertArrayNotHasKey('storage_key', $data['content']['before']);
        $this->assertArrayNotHasKey('path', $data['content']);
    }

    public function test_asset_filters_include_reports_and_admissions_without_an_order(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $mechanic = $this->actor('mechanic');
        $asset = DB::transaction(function () {
            $asset = FleetAsset::create(['code' => 'ORPHAN-'.Str::uuid(), 'type' => 'tractor_unit']);
            TractorUnit::create(['fleet_asset_id' => $asset->id]);

            return $asset;
        });
        $report = $this->actingAs($mechanic)->postJson('/api/workshop/reports', ['request_key' => (string) Str::uuid(), 'fleet_asset_id' => $asset->id, 'description' => 'Reporte sin orden', 'occurred_at' => now()->toIso8601String(), 'reason' => 'Prueba'])->assertCreated()->json('data.report_id');
        $this->getJson('/api/workshop/reports/'.$report)->assertOk();
        $this->actingAs($admin)->postJson('/api/workshop/assets/'.$asset->id.'/admissions', ['request_key' => (string) Str::uuid(), 'kind' => 'corrective', 'admitted_at' => now()->toIso8601String(), 'reason' => 'Ingreso sin orden'])->assertSuccessful();
        $this->getJson('/api/workshop/reports?fleet_asset_id='.$asset->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/workshop/admissions?fleet_asset_id='.$asset->id)->assertOk()->assertJsonPath('meta.total', 1);
    }
}
