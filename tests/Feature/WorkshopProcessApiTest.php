<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopProcessApiTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $this->prepareWorkshopSchema();
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
        $scope = $authorizations->requestScope($admin, $order, (string) Str::uuid(), ['diagnosis_id' => $diagnosis, 'description' => 'Alcance', 'reason' => 'Prueba', 'tasks' => [['line_number' => 1, 'description' => 'Reparar']]]);
        $decision = $authorizations->decide($admin, $scope['request_id'], (string) Str::uuid(), 'approved', 'Aprobado');
        $authorizations->applyScope($admin, $decision['decision_id'], (string) Str::uuid());
        $task = DB::table('workshop_scope_tasks')->where('scope_id', $scope['scope_id'])->value('id');

        return compact('admin', 'mechanic', 'order', 'assignment', 'task');
    }

    private function receipt(int $actor, string $order, string $type, array $extra = []): void
    {
        DB::table('workshop_operations')->insert($extra + ['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => $type, 'actor_id' => $actor, 'reason' => 'Prueba', 'occurred_at' => now(), 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'idempotency_scope' => $type, 'request_fingerprint' => str_repeat('a', 64)]);
    }

    public function test_execution_api_authentication_scope_validation_and_idempotency(): void
    {
        $f = $this->fixture();
        $path = '/api/workshop/orders/'.$f['order'].'/time-events';
        $payload = ['request_key' => (string) Str::uuid(), 'mechanic_assignment_id' => $f['assignment'], 'event_type' => 'start',
            'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Trabajo', 'version' => 3];
        $this->postJson($path, $payload)->assertUnauthorized();
        $other = $this->actor('mechanic');
        $this->actingAs($other)->postJson($path, $payload)->assertForbidden();
        $this->actingAs($f['mechanic'])->postJson($path, $payload + ['actor_id' => $f['admin']->id])->assertUnprocessable()->assertJsonValidationErrors('actor_id');
        $first = $this->actingAs($f['mechanic'])->postJson($path, $payload)->assertSuccessful()->assertJsonStructure(['data' => ['time_event_id']]);
        $this->postJson($path, $payload)->assertSuccessful()->assertExactJson($first->json());
        $this->postJson($path, array_replace($payload, ['reason' => 'Distinto']))->assertStatus(409);
        $this->getJson('/api/workshop/orders/'.$f['order'].'/times')->assertSuccessful()->assertJsonPath('data.person_seconds', 0);
        $pause = array_replace($payload, ['request_key' => (string) Str::uuid(), 'event_type' => 'pause', 'occurred_at' => '2026-10-12T10:00:00Z']);
        $this->postJson($path, $pause)->assertSuccessful();
        $this->getJson('/api/workshop/orders/'.$f['order'].'/times')->assertSuccessful()->assertJsonPath('data.person_seconds', 3600);
        $activity = ['request_key' => (string) Str::uuid(), 'task_id' => $f['task'], 'mechanic_assignment_id' => $f['assignment'],
            'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Prueba', 'description' => 'Reparado'];
        $this->postJson('/api/workshop/orders/'.$f['order'].'/activities', $activity)->assertSuccessful()->assertJsonStructure(['data' => ['activity_id']]);
    }

    public function test_order_authorization_is_constructed_on_server_and_replays_after_application(): void
    {
        $f = $this->fixture();
        $path = '/api/workshop/orders/'.$f['order'].'/authorization-requests';
        $payload = ['request_key' => (string) Str::uuid(), 'type' => 'cancellation', 'version' => 3, 'reason' => 'Cancelación'];
        $this->actingAs($f['mechanic'])->postJson($path, $payload)->assertForbidden();
        $response = $this->actingAs($f['admin'])->postJson($path, $payload)->assertSuccessful();
        $id = $response->json('data.request_id');
        $decision = $this->postJson('/api/workshop/authorization-requests/'.$id.'/decisions', ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Aprobación'])->assertSuccessful()->json('data.decision_id');
        $this->postJson('/api/workshop/decisions/'.$decision.'/apply-order', ['request_key' => (string) Str::uuid()])->assertSuccessful();
        $this->postJson($path, $payload)->assertSuccessful()->assertExactJson($response->json());
        $this->assertSame('cancelled', DB::table('workshop_work_orders')->where('id', $f['order'])->value('status'));
    }

    public function test_admission_and_authorized_exit_replay_and_role_permissions(): void
    {
        $f = $this->fixture();
        $asset = DB::table('workshop_work_orders')->where('id', $f['order'])->value('fleet_asset_id');
        $path = '/api/workshop/assets/'.$asset.'/admissions';
        $payload = ['request_key' => (string) Str::uuid(), 'kind' => 'corrective', 'admitted_at' => '2026-10-12T08:00:00Z', 'reason' => 'Ingreso'];
        $this->actingAs($f['mechanic'])->postJson($path, $payload)->assertForbidden();
        $admitted = $this->actingAs($f['admin'])->postJson($path, $payload)->assertSuccessful();
        $this->postJson($path, $payload)->assertSuccessful()->assertExactJson($admitted->json());
        $admission = $admitted->json('data.admission_id');
        $impediment = $admitted->json('data.impediment_id');
        $this->postJson('/api/workshop/impediments/'.$impediment.'/resolve', ['request_key' => (string) Str::uuid(), 'reason' => 'No autorizado por salida'])->assertUnprocessable();
        $exitRequest = $this->postJson('/api/workshop/admissions/'.$admission.'/exit-requests', [
            'request_key' => (string) Str::uuid(), 'resolve_ids' => [$impediment], 'exited_at' => '2026-10-12T12:00:00Z', 'reason' => 'Salida revisada',
        ])->assertSuccessful()->json('data.request_id');
        $decision = $this->postJson('/api/workshop/authorization-requests/'.$exitRequest.'/decisions', [
            'request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Aprobado',
        ])->assertSuccessful()->json('data.decision_id');
        $key = ['request_key' => (string) Str::uuid()];
        $exited = $this->postJson('/api/workshop/decisions/'.$decision.'/exit', $key)->assertSuccessful();
        $this->postJson('/api/workshop/decisions/'.$decision.'/exit', $key)->assertSuccessful()->assertExactJson($exited->json());
        $this->assertSame('available', DB::table('fleet_assets')->where('id', $asset)->value('service_status'));
    }
}
