<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopCoreApiTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function setupApi(): array
    {
        $this->prepareWorkshopSchema();
        $actor = $this->actor();
        $asset = DB::transaction(function () {
            $asset = FleetAsset::create(['code' => 'API-'.Str::uuid(), 'type' => 'tractor_unit']);
            TractorUnit::create(['fleet_asset_id' => $asset->id]);

            return $asset;
        });

        return [$actor, ['request_key' => (string) Str::uuid(), 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Reparación sintética', 'priority' => 'normal', 'created_at' => now()->toIso8601String(), 'reason' => 'Registro sintético']];
    }

    public function test_authentication_role_and_strict_input_validation(): void
    {
        [$actor, $data] = $this->setupApi();
        $this->postJson('/api/workshop/orders', $data)->assertUnauthorized();
        $this->actingAs($this->actor('mechanic'))->postJson('/api/workshop/orders', $data)->assertForbidden();
        $this->actingAs($actor)->postJson('/api/workshop/orders', $data + ['created_by_id' => 123])->assertUnprocessable();
        $data['priority'] = 'invalid';
        $this->postJson('/api/workshop/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('priority');
    }

    public function test_create_update_replay_conflicting_key_and_stale_version(): void
    {
        [$actor, $data] = $this->setupApi();
        $this->actingAs($actor);
        $created = $this->postJson('/api/workshop/orders', $data)->assertCreated()->assertJsonPath('data.version', 1);
        $id = $created->json('data.order_id');
        $this->postJson('/api/workshop/orders', $data)->assertCreated()->assertJsonPath('data.order_id', $id);
        $changed = $data;
        $changed['description'] = 'Otro contenido';
        $this->postJson('/api/workshop/orders', $changed)->assertStatus(409);
        $update = ['request_key' => (string) Str::uuid(), 'version' => 1, 'description' => 'Actualizada', 'priority' => 'high', 'reason' => 'Cambio oficina'];
        $this->patchJson('/api/workshop/orders/'.$id, $update)->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/workshop/orders/'.$id, $update)->assertOk()->assertJsonPath('data.version', 2);
        $update['request_key'] = (string) Str::uuid();
        $this->patchJson('/api/workshop/orders/'.$id, $update)->assertStatus(409);
        $this->assertDatabaseCount('workshop_work_orders', 1);
        $this->assertDatabaseCount('workshop_operations', 2);
    }

    public function test_reports_diagnoses_and_link_invalidation_preserve_history(): void
    {
        [$actor, $data] = $this->setupApi();
        $this->actingAs($actor);
        $order = $this->postJson('/api/workshop/orders', $data)->assertCreated()->json('data.order_id');
        $reportData = ['request_key' => (string) Str::uuid(), 'fleet_asset_id' => $data['fleet_asset_id'], 'description' => 'Falla sintética', 'occurred_at' => now()->toIso8601String(), 'reason' => 'Reporte'];
        $report = $this->postJson('/api/workshop/reports', $reportData)->assertCreated()->json('data.report_id');
        $revision = ['request_key' => (string) Str::uuid(), 'description' => 'Detalle corregido', 'occurred_at' => now()->toIso8601String(), 'reason' => 'Corrección'];
        $this->postJson('/api/workshop/reports/'.$report.'/revisions', $revision)->assertCreated();
        $link = $this->postJson('/api/workshop/orders/'.$order.'/report-links', ['request_key' => (string) Str::uuid(), 'report_id' => $report, 'reason' => 'Vinculación'])->assertCreated()->json('data.link_id');
        $this->postJson('/api/workshop/report-links/'.$link.'/invalidate', ['request_key' => (string) Str::uuid(), 'reason' => 'Vinculación incorrecta'])->assertOk()->assertJsonPath('data.invalidated', true);
        $diagnosis = ['request_key' => (string) Str::uuid(), 'problem' => 'Falla', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Revisión', 'occurred_at' => now()->toIso8601String(), 'reason' => 'Diagnóstico'];
        $id = $this->postJson('/api/workshop/orders/'.$order.'/diagnoses', $diagnosis)->assertCreated()->json('data.diagnosis_id');
        $diagnosis['request_key'] = (string) Str::uuid();
        $diagnosis['problem'] = 'Falla corregida';
        $this->postJson('/api/workshop/diagnoses/'.$id.'/revisions', $diagnosis)->assertCreated();
        $this->assertDatabaseCount('workshop_reports', 2);
        $this->assertDatabaseCount('workshop_diagnoses', 2);
        $this->assertDatabaseCount('workshop_report_links', 1);
    }

    public function test_mechanic_requires_current_assignment_and_release_preserves_diagnosis(): void
    {
        [$actor, $data] = $this->setupApi();
        $this->actingAs($actor);
        $order = $this->postJson('/api/workshop/orders', $data)->assertCreated()->json('data.order_id');
        $mechanic = $this->actor('mechanic');
        $employee = Employee::create(['user_id' => $mechanic->id, 'name' => 'Mecánico sintético', 'is_active' => true]);
        $diagnosis = ['request_key' => (string) Str::uuid(), 'problem' => 'Falla', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Revisión', 'occurred_at' => now()->toIso8601String(), 'reason' => 'Diagnóstico'];
        $this->actingAs($mechanic)->postJson('/api/workshop/orders/'.$order.'/diagnoses', $diagnosis)->assertForbidden();
        $assignmentData = ['request_key' => (string) Str::uuid(), 'employee_id' => $employee->id, 'starts_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Participación'];
        $assignment = $this->actingAs($actor)->postJson('/api/workshop/orders/'.$order.'/mechanic-assignments', $assignmentData)->assertCreated()->json('data.assignment_id');
        $report = $this->postJson('/api/workshop/reports', ['request_key' => (string) Str::uuid(), 'fleet_asset_id' => $data['fleet_asset_id'], 'description' => 'Reporte oficina', 'occurred_at' => now()->toIso8601String(), 'reason' => 'Reporte'])->assertCreated()->json('data.report_id');
        $this->actingAs($mechanic)->postJson('/api/workshop/reports/'.$report.'/revisions', ['request_key' => (string) Str::uuid(), 'description' => 'Modificación ajena', 'occurred_at' => now()->toIso8601String(), 'reason' => 'Intento'])->assertForbidden();
        $diagnosis['mechanic_assignment_id'] = $assignment;
        $this->actingAs($mechanic)->postJson('/api/workshop/orders/'.$order.'/diagnoses', $diagnosis)->assertCreated();
        $release = ['request_key' => (string) Str::uuid(), 'ends_at' => now()->toIso8601String(), 'reason' => 'Fin participación'];
        $this->actingAs($actor)->postJson('/api/workshop/mechanic-assignments/'.$assignment.'/release', $release)->assertCreated();
        $diagnosis['request_key'] = (string) Str::uuid();
        $this->actingAs($mechanic)->postJson('/api/workshop/orders/'.$order.'/diagnoses', $diagnosis)->assertForbidden();
        $this->assertDatabaseCount('workshop_diagnoses', 1);
        $this->assertDatabaseCount('workshop_mechanic_assignments', 1);
        $this->assertDatabaseCount('workshop_mechanic_releases', 1);
    }
}
