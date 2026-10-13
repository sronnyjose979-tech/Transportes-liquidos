<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopMaterialApiTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function fixture(): array
    {
        $this->prepareWorkshopSchema();
        Carbon::setTestNow('2026-10-12T12:00:00Z');
        DB::beginTransaction();
        $admin = $this->actor();
        $mechanic = $this->actor('mechanic');
        $employee = Employee::create(['user_id' => $mechanic->id, 'name' => 'Mecánico prueba', 'is_active' => true]);
        $asset = FleetAsset::create(['code' => 'EXEC-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);
        $order = (string) Str::uuid();
        $assignment = (string) Str::uuid();
        $diagnosis = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Reparación', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => now(), 'created_by_id' => $admin->id]);
        $this->receipt($admin->id, $order, 'order_create');
        DB::table('workshop_mechanic_assignments')->insert(['id' => $assignment, 'work_order_id' => $order, 'employee_id' => $employee->id, 'starts_at' => '2026-10-12T08:00:00Z', 'assigned_by_id' => $admin->id, 'reason' => 'Asignado']);
        $this->receipt($admin->id, $order, 'mechanic_assign', ['mechanic_assignment_id' => $assignment]);
        DB::table('workshop_diagnoses')->insert(['id' => $diagnosis, 'work_order_id' => $order, 'problem' => 'Falla', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Reparar', 'actor_id' => $admin->id, 'occurred_at' => now(), 'reason' => 'Prueba', 'root_record_id' => $diagnosis, 'revision' => 1]);
        $this->receipt($admin->id, $order, 'diagnosis_record', ['diagnosis_id' => $diagnosis]);
        DB::commit();
        $authorizations = app(WorkshopAuthorizations::class);
        $catalog = SparePart::create(['code' => 'PART-'.Str::uuid(), 'description' => 'Junta', 'unit' => 'unidad']);
        $scope = $authorizations->requestScope($admin, $order, (string) Str::uuid(), ['diagnosis_id' => $diagnosis, 'description' => 'Alcance', 'reason' => 'Prueba', 'tasks' => [['line_number' => 1, 'description' => 'Reparar']], 'parts' => [['line_number' => 1, 'task_line_number' => 1, 'spare_part_id' => $catalog->id, 'quantity' => '2.000000']]]);
        $decision = $authorizations->decide($admin, $scope['request_id'], (string) Str::uuid(), 'approved', 'Aprobado');
        $authorizations->applyScope($admin, $decision['decision_id'], (string) Str::uuid());
        $task = DB::table('workshop_scope_tasks')->where('scope_id', $scope['scope_id'])->value('id');

        $part = DB::table('workshop_scope_parts')->where('scope_id', $scope['scope_id'])->value('id');

        return compact('admin', 'mechanic', 'order', 'assignment', 'task', 'part', 'catalog', 'scope', 'diagnosis');
    }

    private function receipt(int $actor, string $order, string $type, array $extra = []): void
    {
        DB::table('workshop_operations')->insert($extra + ['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => $type, 'actor_id' => $actor, 'reason' => 'Prueba', 'occurred_at' => now(), 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'idempotency_scope' => $type, 'request_fingerprint' => str_repeat('a', 64)]);
    }

    public function test_deliveries_and_usages_replay_and_reject_excess_without_inventory_moves(): void
    {
        $f = $this->fixture();
        $data = ['request_key' => (string) Str::uuid(), 'scope_part_id' => $f['part'], 'delivered_to_assignment_id' => $f['assignment'], 'quantity' => '2.000000', 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Entrega'];
        $url = '/api/workshop/orders/'.$f['order'].'/deliveries';
        $first = $this->actingAs($f['admin'])->postJson($url, $data)->assertSuccessful()->json('data');
        $this->postJson($url, $data)->assertSuccessful()->assertJsonPath('data.delivery_id', $first['delivery_id']);
        $this->postJson($url, array_replace($data, ['quantity' => '1']))->assertStatus(409);
        $usage = ['request_key' => (string) Str::uuid(), 'delivery_id' => $first['delivery_id'], 'mechanic_assignment_id' => $f['assignment'], 'quantity' => '1.250001', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Uso'];
        $useUrl = '/api/workshop/orders/'.$f['order'].'/usages';
        $this->actingAs($f['mechanic'])->postJson($useUrl, $usage)->assertSuccessful();
        $this->postJson($useUrl, array_replace($usage, ['request_key' => (string) Str::uuid(), 'quantity' => '0.75']))->assertUnprocessable();
        $this->assertSame(1, DB::table('workshop_part_deliveries')->count());
        $this->assertSame(1, DB::table('workshop_part_usages')->count());
    }

    public function test_material_permissions_scope_and_strict_decimals(): void
    {
        $f = $this->fixture();
        $url = '/api/workshop/orders/'.$f['order'].'/deliveries';
        $data = ['request_key' => (string) Str::uuid(), 'scope_part_id' => $f['part'], 'quantity' => '1', 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Entrega'];
        $this->postJson($url, $data)->assertUnauthorized();
        $this->actingAs($f['mechanic'])->postJson($url, $data)->assertForbidden();
        foreach (['1.0000001', '1e2', 'NaN', '1000000000000', 1, '-1', '0'] as $value) {
            $this->actingAs($f['admin'])->postJson($url, array_replace($data, ['quantity' => $value]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->postJson($url, $data + ['recorded_by_id' => $f['mechanic']->id])->assertUnprocessable()->assertJsonValidationErrors('recorded_by_id');
        $other = $this->actor('mechanic');
        $this->actingAs($other)->postJson('/api/workshop/orders/'.$f['order'].'/material-needs', ['request_key' => (string) Str::uuid(), 'description' => 'Necesario', 'quantity' => '1', 'spare_part_id' => $f['catalog']->id, 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Solicitud'])->assertForbidden();
    }

    public function test_costs_totals_and_administrative_rectification(): void
    {
        $f = $this->fixture();
        $url = '/api/workshop/orders/'.$f['order'].'/costs';
        $data = ['request_key' => (string) Str::uuid(), 'scope_task_id' => $f['task'], 'cost_kind' => 'labor', 'basis' => 'actual', 'amount' => '12.123456', 'currency' => 'CRC', 'description' => 'Servicio', 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Costo'];
        $this->actingAs($f['mechanic'])->postJson($url, $data)->assertForbidden();
        $cost = $this->actingAs($f['admin'])->postJson($url, $data)->assertSuccessful()->json('data.cost_record_id');
        $this->postJson($url, $data)->assertSuccessful()->assertJsonPath('data.cost_record_id', $cost);
        $this->getJson('/api/workshop/orders/'.$f['order'].'/cost-totals')->assertOk()->assertJsonPath('data.0.amount', '12.123456');
        $version = DB::table('workshop_work_orders')->where('id', $f['order'])->value('version');
        $request = ['request_key' => (string) Str::uuid(), 'version' => $version, 'executor_id' => $f['admin']->id, 'changes' => ['amount' => '15.000001', 'reason' => 'Rectificado'], 'reason' => 'Error comprobado'];
        $this->postJson('/api/workshop/facts/cost/'.$cost.'/rectifications', array_replace($request, ['changes' => ['actor_id' => $f['mechanic']->id]]))->assertUnprocessable()->assertJsonValidationErrors('changes');
        $this->postJson('/api/workshop/facts/cost/'.$cost.'/rectifications', array_replace($request, ['changes' => ['amount' => '100000000000000']]))->assertUnprocessable()->assertJsonValidationErrors('changes.amount');
        $correction = $this->postJson('/api/workshop/facts/cost/'.$cost.'/rectifications', $request)->assertSuccessful()->json('data.request_id');
        $decision = app(WorkshopAuthorizations::class)->decide($f['admin'], $correction, (string) Str::uuid(), 'approved', 'Corregir');
        $this->postJson('/api/workshop/decisions/'.$decision['decision_id'].'/facts/cost/apply', ['request_key' => (string) Str::uuid()])->assertSuccessful();
        $this->getJson('/api/workshop/orders/'.$f['order'].'/cost-totals')->assertOk()->assertJsonPath('data.0.amount', '15.000001');
        $this->assertSame(2, DB::table('workshop_cost_records')->count());
        $this->actingAs($f['mechanic'])->getJson('/api/workshop/orders/'.$f['order'].'/cost-totals')->assertForbidden();
    }

    public function test_needs_external_repairs_and_batch_validation(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['mechanic'])->postJson('/api/workshop/orders/'.$f['order'].'/material-needs', ['request_key' => (string) Str::uuid(), 'description' => 'Necesario', 'quantity' => '1.000001', 'spare_part_id' => $f['catalog']->id, 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Solicitud'])->assertSuccessful();
        $this->actingAs($f['admin'])->postJson('/api/workshop/rectifications/apply-batch', ['request_key' => (string) Str::uuid(), 'decisions' => []])->assertUnprocessable();
        $this->postJson('/api/workshop/facts/raw-table/'.Str::uuid().'/rectifications', [])->assertNotFound();
        $this->postJson('/api/workshop/orders/'.$f['order'].'/external-repairs', ['request_key' => (string) Str::uuid(), 'description' => 'Externo', 'scope_task_id' => $f['task'], 'supplier_id' => (string) Str::uuid(), 'performed_at' => '2026-10-12T09:00:00Z', 'reason' => 'Externo'])->assertUnprocessable();
    }
}
