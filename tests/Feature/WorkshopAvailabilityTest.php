<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Models\FleetOperatingImpediment;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use App\Modules\Workshop\Application\WorkshopAvailability;
use App\Modules\Workshop\Application\WorkshopCommands;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopAvailabilityTest extends PostgresTestCase
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
        $actor = $this->actor();
        $asset = app(FleetAdministration::class)->save($actor, 'tractor_unit', ['code' => 'TAL-'.Str::uuid()]);

        return [$actor, $asset, app(WorkshopAvailability::class)];
    }

    private function admit($actor, $asset, $service, ?string $key = null): array
    {
        return $service->admit($actor, $asset->id, $key ?? (string) Str::uuid(), ['kind' => 'corrective', 'admitted_at' => '2026-10-12T12:00:00Z', 'reason' => 'Ingreso sintético']);
    }

    private function approveExit($actor, $service, array $admission, array $extra = []): array
    {
        $request = $service->requestExit($actor, $admission['admission_id'], (string) Str::uuid(), $extra + ['resolve_ids' => [$admission['impediment_id']], 'exited_at' => '2026-10-12T13:00:00Z', 'reason' => 'Revisión de salida']);

        return app(WorkshopAuthorizations::class)->decide($actor, $request['request_id'], (string) Str::uuid(), 'approved', 'Autorización');
    }

    public function test_admission_blocks_asset_and_replays_without_duplicates(): void
    {
        [$actor,$asset,$service] = $this->fixture();
        $key = (string) Str::uuid();
        $result = $this->admit($actor, $asset, $service, $key);
        $this->assertSame($result, $this->admit($actor, $asset, $service, $key));
        $this->assertSame('maintenance', $asset->refresh()->service_status);
        $this->assertSame(1, DB::table('workshop_admissions')->count());
        $cause = FleetOperatingImpediment::findOrFail($result['impediment_id']);
        $this->assertSame($result['admission_id'], $cause->admission->id);
        $this->assertSame($actor->id, $cause->actor->id);
    }

    public function test_second_admission_rolls_back(): void
    {
        [$actor,$asset,$service] = $this->fixture();
        $this->admit($actor, $asset, $service);
        try {
            $this->admit($actor, $asset, $service);
            $this->fail('Doble ingreso aceptado.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(1, DB::table('workshop_admissions')->count());
        $this->assertSame(1, DB::table('fleet_operating_impediments')->count());
    }

    public function test_authorized_exit_resolves_canonical_cause_once(): void
    {
        [$actor,$asset,$service] = $this->fixture();
        $admission = $this->admit($actor, $asset, $service);
        $decision = $this->approveExit($actor, $service, $admission);
        $key = (string) Str::uuid();
        $result = $service->exit($actor, $decision['decision_id'], $key);
        $this->assertSame($result, $service->exit($actor, $decision['decision_id'], $key));
        $this->assertSame('available', $asset->refresh()->service_status);
        $this->assertSame(2, DB::table('workshop_admissions')->value('version'));
        $this->assertSame(1, DB::table('fleet_impediment_resolutions')->count());
        $this->assertSame(1, DB::table('workshop_admission_exits')->count());
        $this->expectException(ValidationException::class);
        $service->exit($actor, $decision['decision_id'], (string) Str::uuid());
    }

    public function test_rejected_exit_cannot_apply_and_rolls_back(): void
    {
        [$actor,$asset,$service] = $this->fixture();
        $admission = $this->admit($actor, $asset, $service);
        $request = $service->requestExit($actor, $admission['admission_id'], (string) Str::uuid(), ['resolve_ids' => [$admission['impediment_id']], 'exited_at' => '2026-10-12T13:00:00Z', 'reason' => 'Revisión']);
        $decision = app(WorkshopAuthorizations::class)->decide($actor, $request['request_id'], (string) Str::uuid(), 'rejected', 'Falta reparación');
        try {
            $service->exit($actor, $decision['decision_id'], (string) Str::uuid());
            $this->fail('Rechazo aplicado.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('workshop_admission_exits')->count());
        $this->assertSame('maintenance', $asset->refresh()->service_status);
    }

    public function test_direct_sql_cannot_release_asset_or_mutate_history(): void
    {
        [$actor,$asset,$service] = $this->fixture();
        $admission = $this->admit($actor, $asset, $service);
        foreach ([fn () => DB::table('fleet_assets')->where('id', $asset->id)->update(['service_status' => 'available']), fn () => DB::table('fleet_operating_impediments')->where('id', $admission['impediment_id'])->delete(), fn () => DB::table('workshop_admissions')->where('id', $admission['admission_id'])->update(['reason' => 'Alterado'])] as $write) {
            try {
                DB::transaction(function () use ($write) {
                    $write();
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                });
                $this->fail('SQL incompatible aceptado.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertSame('maintenance', $asset->refresh()->service_status);
    }

    public function test_office_ingress_and_admin_only_exit_permissions(): void
    {
        [$admin,$asset,$service] = $this->fixture();
        $secretary = $this->actor('secretary');
        $admission = $this->admit($secretary, $asset, $service);
        $this->assertSame($secretary->id, DB::table('workshop_admissions')->value('admitted_by_id'));
        $this->expectException(AuthorizationException::class);
        $service->requestExit($secretary, $admission['admission_id'], (string) Str::uuid(), ['resolve_ids' => [$admission['impediment_id']], 'exited_at' => '2026-10-12T13:00:00Z', 'reason' => 'Sin permiso']);
    }

    public function test_canonical_cause_cannot_be_resolved_without_authorized_exit(): void
    {
        [$actor,$asset,$service] = $this->fixture();
        $admission = $this->admit($actor, $asset, $service);
        try {
            $service->resolve($actor, $admission['impediment_id'], (string) Str::uuid(), 'Intento');
            $this->fail('Causa canónica liberada.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('fleet_impediment_resolutions')->count());
        $this->assertSame('maintenance', $asset->refresh()->service_status);
    }

    private function order($actor, $asset): string
    {
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $actor, $asset) {
            DB::table('workshop_work_orders')->insert(['id' => $id, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Reparación', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => '2026-10-12T12:00:00Z', 'created_by_id' => $actor->id]);
            app(WorkshopCommands::class)->record($actor, 'order_create:'.$id, (string) Str::uuid(), [], ['work_order_id' => $id, 'operation_type' => 'order_create', 'reason' => 'Fixture', 'after_values' => []]);
        });

        return $id;
    }

    public function test_new_cause_invalidates_existing_exit_review(): void
    {
        [$actor,$asset,$service] = $this->fixture();
        $order = $this->order($actor, $asset);
        $admission = $this->admit($actor, $asset, $service);
        $decision = $this->approveExit($actor, $service, $admission);
        $service->recordImpediment($actor, $asset->id, (string) Str::uuid(), ['work_order_id' => $order, 'effect' => 'out_of_service', 'description' => 'Causa independiente', 'occurred_at' => '2026-10-12T12:30:00Z', 'reason' => 'Revisión']);
        try {
            $service->exit($actor, $decision['decision_id'], (string) Str::uuid());
            $this->fail('Revisión obsoleta aplicada.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame('out_of_service', $asset->refresh()->service_status);
        $this->assertSame(0, DB::table('workshop_admission_exits')->count());
    }

    public function test_exit_preserves_independent_cause_and_order_history(): void
    {
        [$actor,$asset,$service] = $this->fixture();
        $order = $this->order($actor, $asset);
        $cause = $service->recordImpediment($actor, $asset->id, (string) Str::uuid(), ['work_order_id' => $order, 'effect' => 'out_of_service', 'description' => 'Causa independiente', 'occurred_at' => '2026-10-12T11:30:00Z', 'reason' => 'Revisión']);
        $admission = $this->admit($actor, $asset, $service);
        $decision = $this->approveExit($actor, $service, $admission);
        $service->exit($actor, $decision['decision_id'], (string) Str::uuid());
        $this->assertSame('out_of_service', $asset->refresh()->service_status);
        $this->assertFalse(DB::table('fleet_impediment_resolutions')->where('impediment_id', $cause['impediment_id'])->exists());
        $this->assertSame('pending_diagnosis', DB::table('workshop_work_orders')->where('id', $order)->value('status'));
    }

    public function test_linked_repair_cause_must_resolve_before_exit(): void
    {
        [$actor,$asset,$service] = $this->fixture();
        $order = $this->order($actor, $asset);
        $admission = $this->admit($actor, $asset, $service);
        $service->linkOrder($actor, $order, $admission['admission_id'], (string) Str::uuid(), 'Vínculo');
        $service->recordImpediment($actor, $asset->id, (string) Str::uuid(), ['work_order_id' => $order, 'effect' => 'maintenance', 'description' => 'Reparación pendiente', 'occurred_at' => '2026-10-12T12:30:00Z', 'reason' => 'Revisión']);
        $decision = $this->approveExit($actor, $service, $admission);
        try {
            $service->exit($actor, $decision['decision_id'], (string) Str::uuid());
            $this->fail('Reparación pendiente ignorada.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('workshop_admission_exits')->count());
        $this->assertSame(1, DB::table('workshop_order_admission_links')->count());
    }

    public function test_unknown_legacy_status_requires_real_observation_and_survives_exit(): void
    {
        [$actor, $asset, $service] = $this->fixture();
        // Only this disposable schema is changed; simulate a value recorded before
        // the availability protection existed, without inventing historical facts.
        $migration = require base_path('database/migrations/2026_10_12_000230_protect_workshop_availability.php');
        $migration->down();
        DB::table('fleet_assets')->where('id', $asset->id)->update(['service_status' => 'out_of_service']);
        $migration->up();
        $this->assertSame(0, DB::table('fleet_operating_impediments')->count());
        try {
            $this->admit($actor, $asset, $service);
            $this->fail('Ingreso aceptado sin conciliación.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        try {
            DB::transaction(function () use ($asset): void {
                DB::table('fleet_assets')->where('id', $asset->id)->update(['service_status' => 'available']);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Estado previo liberado sin observación.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $observed = $service->captureHistorical($actor, $asset->id, (string) Str::uuid(), 'Conciliación administrativa real');
        $cause = FleetOperatingImpediment::findOrFail($observed['impediment_id']);
        $this->assertSame('historical_observation', $cause->kind);
        $this->assertSame($actor->id, $cause->actor_id);
        $this->assertNull($cause->admission_id);
        $this->assertNull($cause->work_order_id);
        $this->assertSame('out_of_service', $asset->refresh()->service_status);
        $admission = $this->admit($actor, $asset, $service);
        $decision = $this->approveExit($actor, $service, $admission);
        $service->exit($actor, $decision['decision_id'], (string) Str::uuid());
        $this->assertSame('out_of_service', $asset->refresh()->service_status);
        $this->assertFalse(DB::table('fleet_impediment_resolutions')->where('impediment_id', $cause->id)->exists());
        $this->assertSame(1, DB::table('workshop_admission_exits')->count());
    }

    public function test_secretary_can_confirm_operating_cause_but_mechanic_cannot(): void
    {
        [$actor, $asset, $service] = $this->fixture();
        $order = $this->order($actor, $asset);
        $secretary = $this->actor('secretary');
        $data = ['work_order_id' => $order, 'effect' => 'maintenance', 'description' => 'Avería confirmada por oficina', 'occurred_at' => '2026-10-12T12:30:00Z', 'reason' => 'Confirmación'];
        $cause = $service->recordImpediment($secretary, $asset->id, (string) Str::uuid(), $data);
        $this->assertSame($secretary->id, FleetOperatingImpediment::findOrFail($cause['impediment_id'])->actor_id);
        $this->assertSame('maintenance', $asset->refresh()->service_status);
        $this->expectException(AuthorizationException::class);
        $service->recordImpediment($this->actor('mechanic'), $asset->id, (string) Str::uuid(), $data);
    }
}
