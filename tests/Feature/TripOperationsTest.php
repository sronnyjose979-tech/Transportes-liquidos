<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use App\Modules\TransportAndDispatch\Application\TripOperations;
use App\Modules\TransportAndDispatch\Models\LoadItem;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationApplication;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationDecision;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationRequest;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripLoad;
use App\Modules\TransportAndDispatch\Models\UnloadItem;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TripOperationsTest extends PostgresTestCase
{
    use TransportFixtures;

    private function service(): TripOperations
    {
        return app(TripOperations::class);
    }

    private function ready(): Trip
    {
        $trip = $this->trip($this->request());
        $this->ensureAssignments($trip);

        return $trip;
    }

    private function invalid(callable $write): void
    {
        try {
            $write();
            $this->fail('Operación debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function load(Trip $trip, string $amount = '250.25'): TripLoad
    {
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->firstOrFail();
        $compartment = $assignment->fleetAsset->tanker->compartments->first();

        return $this->service()->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            [$compartment->id => $amount], CarbonImmutable::now(), 'Carga comunicada', ['source' => 'Conductor']);
    }

    public function test_official_start_requires_office_and_ready_assignments_and_is_audited(): void
    {
        $empty = $this->trip($this->request());
        $this->invalid(fn () => $this->service()->start($empty->registeredBy, $empty->id, 'Sin recursos'));
        $this->assertSame('pending', $empty->fresh()->execution_status);
        $trip = $this->ready();
        $secretary = $this->actor('secretary');
        $started = $this->service()->start($secretary, $trip->id, 'Confirmación manual');
        $this->assertSame('in_progress', $started->execution_status);
        $this->assertSame($secretary->id, $started->started_by_id);
        $this->assertNotNull($started->officially_started_at);
        $this->assertSame('official_start', $trip->changes()->first()->action);
        $this->assertSame(1, $trip->driverAssignments()->current()->count());
        $this->assertSame(2, $trip->assetAssignments()->current()->count());
        $this->invalid(fn () => $this->service()->start($secretary, $trip->id, 'Duplicado'));
    }

    public function test_load_and_unload_are_unique_real_quantities_and_never_finalize_automatically(): void
    {
        $trip = $this->ready();
        $load = $this->load($trip);
        $this->assertSame('pending', $trip->fresh()->execution_status);
        $trip = $this->service()->start($trip->registeredBy, $trip->id, 'Inicio');
        $unload = $this->service()->unload($this->actor('secretary'), $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items->first()->id => '225.25'], CarbonImmutable::now(), 'Descarga comunicada');
        $this->assertTrue($load->trip->is($trip));
        $this->assertTrue($unload->tripLoad->is($load));
        $this->assertSame('250.25', $load->items->first()->loaded_liters);
        $this->assertSame('225.25', $unload->items->first()->delivered_liters);
        $this->assertSame('in_progress', $trip->fresh()->execution_status);
        $this->assertNull($trip->fresh()->officially_completed_at);
        $this->assertSame(1, $trip->driverAssignments()->current()->count());
        $this->assertSame(2, $trip->assetAssignments()->current()->count());
        $this->invalid(fn () => $this->load($trip));
        $this->invalid(fn () => $this->service()->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items->first()->id => '225.25'], CarbonImmutable::now(), 'Duplicada'));
        $this->assertSame(1, $trip->tripLoad()->count());
        $this->assertSame(1, $trip->unload()->count());
    }

    public function test_capacity_snapshot_comes_from_actual_compartment_and_survives_later_edit(): void
    {
        $trip = $this->ready();
        $this->invalid(fn () => $this->load($trip, '1000.01'));
        $this->assertSame(0, $trip->tripLoad()->count());
        $load = $this->load($trip, '1000.00');
        $item = $load->items->first();
        $this->assertSame('1000.00', $item->capacity_liters_snapshot);
        $compartment = $item->compartment;
        $compartment->update(['capacity_liters' => '1200', 'code' => 'Nuevo']);
        $this->assertSame('1000.00', $item->fresh()->capacity_liters_snapshot);
        $this->assertSame('A', $item->fresh()->compartment_code_snapshot);
        $this->assertSame('Agua', $trip->fresh()->product_name_snapshot);
        $this->rejected(fn () => DB::table('load_items')->where('id', $item->id)->update(['loaded_liters' => '100']));
    }

    public function test_wrong_compartment_and_unload_from_another_trip_are_rejected_atomically(): void
    {
        $first = $this->ready();
        $second = $this->ready();
        $load = $this->load($first);
        $other = $this->load($second);
        $this->invalid(fn () => $this->service()->unload($first->registeredBy, $first->id, $first->transportRequest->route->destination_site_id,
            [$other->items->first()->id => '100'], CarbonImmutable::now(), 'Otro viaje'));
        $this->assertSame(0, $first->unload()->count());
        $item = $load->items->first();
        $this->rejected(fn () => LoadItem::create(['trip_load_id' => $load->id, 'compartment_id' => $other->items->first()->compartment_id,
            'loaded_liters' => '1', 'recorded_by_id' => $first->registered_by_id, 'occurred_at' => CarbonImmutable::now(), 'reason' => 'Otro compartimento']));
        $this->assertSame(1, $load->items()->count());
    }

    public function test_assigned_driver_reports_without_official_start_or_completion_privileges(): void
    {
        $trip = $this->ready();
        $driver = $trip->driverAssignments()->current()->first()->employee->user;
        $event = $this->service()->report($driver, $trip->id, 'completion_report', ['report' => 'Terminé'], CarbonImmutable::now());
        $this->assertSame($driver->id, $event->created_by_id);
        $this->assertSame('pending', $trip->fresh()->execution_status);
        $this->assertNull($trip->fresh()->officially_completed_at);
        try {
            $this->service()->start($driver, $trip->id, 'Conductor');
            $this->fail('Conductor no inicia oficialmente.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('pending', $trip->fresh()->execution_status);
        }
        $other = $this->ready();
        $this->expectException(AuthorizationException::class);
        $this->service()->report($driver, $other->id, 'information', ['data' => 'Ajeno'], CarbonImmutable::now());
    }

    public function test_odometer_corrections_preserve_history_and_exact_distance_by_assignment(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tractor_unit')->first();
        $initial = $this->service()->reading($trip->registeredBy, $trip->id, $assignment->id, 'initial', '1000.125', CarbonImmutable::now(), 'Lectura inicial');
        $final = $this->service()->reading($trip->registeredBy, $trip->id, $assignment->id, 'final', '1050.625', CarbonImmutable::now(), 'Lectura final');
        $this->assertSame('50.500', $this->service()->distance($trip->registeredBy, $trip->id, $assignment->id));
        $original = $this->row($final);
        $corrected = $this->service()->correctReading($this->actor('secretary'), $final->id, '1051.125', CarbonImmutable::now(), 'Corrección recibida');
        $this->assertSame('51.000', $this->service()->distance($trip->registeredBy, $trip->id, $assignment->id));
        $this->assertSame($original, $this->row($final));
        $this->assertTrue($corrected->previousReading->is($final));
        $this->assertTrue($final->successor->is($corrected));
        $this->assertSame(2, $trip->odometerReadings()->current()->count());
        $this->invalid(fn () => $this->service()->correctReading($trip->registeredBy, $final->id, '1060', CarbonImmutable::now(), 'Rama concurrente'));
        $this->rejected(fn () => DB::table('trip_odometer_readings')->where('id', $initial->id)->delete());
    }

    public function test_odometer_reset_is_preserved_without_inventing_distance_compensation(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tractor_unit')->first();
        $this->service()->reading($trip->registeredBy, $trip->id, $assignment->id, 'initial', '1000', CarbonImmutable::now(), 'Inicial');
        $this->service()->reading($trip->registeredBy, $trip->id, $assignment->id, 'final', '10', CarbonImmutable::now(), 'Final comunicada');
        $this->assertNull($this->service()->distance($trip->registeredBy, $trip->id, $assignment->id));
        $this->assertSame(2, $trip->odometerReadings()->count());
    }

    public function test_revoked_or_unassigned_driver_cannot_report_and_non_office_cannot_record_quantities(): void
    {
        $trip = $this->ready();
        $driver = $trip->driverAssignments()->current()->first()->employee->user;
        $driver->roles()->detach();
        try {
            $this->service()->report($driver, $trip->id, 'incident', ['report' => 'Revocado'], CarbonImmutable::now());
            $this->fail('Rol revocado.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(0, $trip->events()->count());
        }
        $this->expectException(AuthorizationException::class);
        $this->service()->load($this->actor('mechanic'), $trip->id, $trip->transportRequest->route->origin_site_id, [], CarbonImmutable::now(), 'No autorizado');
    }

    public function test_multi_compartment_load_and_unload_keep_one_product_and_one_operation(): void
    {
        $trip = $this->trip($this->request());
        $resources = $this->assignmentResources();
        $tank = app(FleetAdministration::class)->save($trip->registeredBy, 'tanker', [
            'code' => (string) Str::uuid(), 'has_physical_partitions' => true,
            'compartments' => [['code' => 'A', 'capacity_liters' => '300', 'is_active' => true], ['code' => 'B', 'capacity_liters' => '700', 'is_active' => true]]]);
        $assignments = app(AssignmentAdministration::class);
        foreach (['driver', 'tractor_unit'] as $kind) {
            $assignments->assign($trip->registeredBy, $trip->id, $kind, $resources[$kind]->id, 'Preparación');
        }
        $assignments->assign($trip->registeredBy, $trip->id, 'tanker', $tank->id, 'Cisterna dividida');
        $amounts = [];
        foreach ($tank->tanker->compartments as $compartment) {
            $amounts[$compartment->id] = $compartment->capacity_liters;
        }
        $load = $this->service()->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id, $amounts, CarbonImmutable::now(), 'Carga distribuida');
        $unloadAmounts = [];
        foreach ($load->items as $item) {
            $unloadAmounts[$item->id] = $item->loaded_liters;
        }
        $unload = $this->service()->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id, $unloadAmounts, CarbonImmutable::now(), 'Descarga distribuida');
        $this->assertCount(2, $load->items);
        $this->assertCount(2, $unload->items);
        $this->assertSame('1000.00', DB::selectOne('SELECT sum(loaded_liters)::text AS total FROM load_items WHERE trip_load_id=?', [$load->id])->total);
        $this->assertSame($trip->liquid_product_id, $load->trip->liquid_product_id);
        $this->assertSame('Agua', $trip->fresh()->product_name_snapshot);
    }

    public function test_compartment_differences_need_approval_even_when_total_liters_match(): void
    {
        $trip = $this->trip($this->request());
        $resources = $this->assignmentResources();
        $tank = app(FleetAdministration::class)->save($trip->registeredBy, 'tanker', [
            'code' => (string) Str::uuid(), 'has_physical_partitions' => true,
            'compartments' => [['code' => 'A', 'capacity_liters' => '300', 'is_active' => true], ['code' => 'B', 'capacity_liters' => '700', 'is_active' => true]]]);
        foreach (['driver', 'tractor_unit'] as $kind) {
            app(AssignmentAdministration::class)->assign($trip->registeredBy, $trip->id, $kind, $resources[$kind]->id, 'Preparación');
        }
        app(AssignmentAdministration::class)->assign($trip->registeredBy, $trip->id, 'tanker', $tank->id, 'Cisterna dividida');
        $trip = $this->service()->start($trip->registeredBy, $trip->id, 'Inicio');
        $amounts = $tank->tanker->compartments->mapWithKeys(fn ($compartment) => [$compartment->id => '100.00'])->all();
        $load = $this->service()->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            $amounts, CarbonImmutable::now(), 'Carga');
        $unloadAmounts = [$load->items[0]->id => '90.00', $load->items[1]->id => '110.00'];
        $this->service()->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items[0]->id => '90.00'], CarbonImmutable::now(), 'Descarga parcial comunicada');
        $this->invalid(fn () => $this->service()->finish($trip->registeredBy, $trip->id, 'Falta segundo compartimento'));
        UnloadItem::create(['trip_unload_id' => $trip->unload()->first()->id,
            'load_item_id' => $load->items[1]->id, 'delivered_liters' => $unloadAmounts[$load->items[1]->id],
            'recorded_by_id' => $trip->registered_by_id, 'occurred_at' => CarbonImmutable::now(), 'reason' => 'Segundo detalle comunicado']);
        $content = json_decode(DB::selectOne('SELECT trip_volume_content(?)::text AS content', [$trip->id])->content, true);
        $this->assertSame($content['loaded_liters'], $content['delivered_liters']);
        $this->assertCount(2, $content['items']);
        $this->invalid(fn () => $this->service()->finish($trip->registeredBy, $trip->id, 'Sin autorización'));
        $this->rejected(fn () => $this->change($trip, ['execution_status' => 'completed', 'officially_completed_at' => CarbonImmutable::now(), 'completed_by_id' => $trip->registered_by_id]));
        $trip->refresh();
        $intent = TransportAuthorizationRequest::create(['trip_id' => $trip->id, 'type' => 'volume_difference', 'content' => $content,
            'target_version' => $trip->version, 'requested_by_id' => $trip->registered_by_id, 'reason' => 'Diferencias por compartimento']);
        $decision = TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => 'approved',
            'decided_by_id' => $trip->registered_by_id, 'reason' => 'Administrador autoriza cada cantidad']);
        $finished = $this->service()->finish($trip->registeredBy, $trip->id, 'Confirmación oficial', $decision->id);
        $this->assertSame('completed', $finished->execution_status);
        $this->assertNotNull($decision->fresh()->application);
    }

    public function test_driver_cannot_write_definitive_volumes_even_through_sql_and_two_decimal_input_is_preserved(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $driver = $trip->driverAssignments()->current()->first()->employee->user;
        $this->rejected(fn () => TripLoad::create(['trip_id' => $trip->id, 'tanker_assignment_id' => $assignment->id,
            'operation_site_id' => $trip->transportRequest->route->origin_site_id, 'recorded_by_id' => $driver->id,
            'occurred_at' => CarbonImmutable::now(), 'reason' => 'Solo oficina registra volúmenes']));
        $this->assertSame(0, $trip->tripLoad()->count());
        $this->expectException(\InvalidArgumentException::class);
        $this->load($trip, '250.251');
    }

    public function test_invalid_quantities_and_nan_cannot_bypass_snapshot_capacity(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $compartment = $assignment->fleetAsset->tanker->compartments->first();
        $load = TripLoad::create(['trip_id' => $trip->id, 'tanker_assignment_id' => $assignment->id,
            'operation_site_id' => $trip->transportRequest->route->origin_site_id, 'recorded_by_id' => $trip->registered_by_id,
            'occurred_at' => CarbonImmutable::now(), 'reason' => 'Cabecera comunicada']);
        foreach (['1000.01', 'NaN'] as $amount) {
            $this->rejected(fn () => DB::table('load_items')->insert(['id' => (string) Str::uuid(),
                'trip_load_id' => $load->id, 'compartment_id' => $compartment->id, 'loaded_liters' => $amount,
                'capacity_liters_snapshot' => '9999', 'compartment_code_snapshot' => 'Inventado', 'recorded_by_id' => $trip->registered_by_id,
                'occurred_at' => CarbonImmutable::now(), 'reason' => 'SQL sintético']));
        }
        $this->assertSame(0, $load->items()->count());
    }

    public function test_recorded_difference_cannot_be_finalized_via_sql_without_exact_consumed_approval(): void
    {
        $trip = $this->ready();
        $load = $this->load($trip);
        $trip = $this->service()->start($trip->registeredBy, $trip->id, 'Inicio');
        $unload = $this->service()->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items->first()->id => '200.00'], CarbonImmutable::now(), 'Descarga');
        $fields = ['execution_status' => 'completed', 'officially_completed_at' => CarbonImmutable::now(), 'completed_by_id' => $trip->registered_by_id];
        $this->rejected(fn () => $this->change($trip, $fields));
        $trip->refresh();
        $intent = TransportAuthorizationRequest::create(['trip_id' => $trip->id, 'type' => 'volume_difference',
            'target_version' => $trip->version, 'requested_by_id' => $trip->registered_by_id, 'reason' => 'Diferencia comunicada',
            'content' => json_decode(DB::selectOne('SELECT trip_volume_content(?)::text AS content', [$trip->id])->content, true)]);
        $decision = TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id,
            'decision' => 'approved', 'decided_by_id' => $trip->registered_by_id, 'reason' => 'Administrador autoriza valores concretos']);
        $this->rejected(fn () => $this->change($trip, $fields)); // Approval alone is not an application.
        $trip->refresh();
        $change = $this->change($trip, $fields);
        $application = TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id,
            'validation_type' => 'volume_difference', 'applied_by_id' => $trip->registered_by_id]);
        $this->checkConstraints();
        $this->assertSame('completed', $trip->fresh()->execution_status);
        $this->assertSame(1, $trip->driverAssignments()->current()->count());
        $this->assertSame(2, $trip->assetAssignments()->current()->count());
        $this->rejected(fn () => DB::table('transport_authorization_applications')->where('id', $application->id)->delete());
        $this->rejected(fn () => TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id,
            'validation_type' => 'volume_difference', 'applied_by_id' => $trip->registered_by_id]), '23505');
    }

    public function test_volume_difference_approval_is_exclusive_and_wrong_quantities_cannot_be_applied(): void
    {
        $trip = $this->ready();
        $load = $this->load($trip);
        $trip = $this->service()->start($trip->registeredBy, $trip->id, 'Inicio');
        $unload = $this->service()->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items->first()->id => '200.00'], CarbonImmutable::now(), 'Descarga');
        $intent = TransportAuthorizationRequest::create(['trip_id' => $trip->id, 'type' => 'volume_difference',
            'target_version' => $trip->version, 'requested_by_id' => $trip->registered_by_id, 'reason' => 'Valores incorrectos',
            'content' => ['load_id' => $load->id, 'unload_id' => $unload->id, 'loaded_liters' => '251.25', 'delivered_liters' => '200.00']]);
        $this->rejected(fn () => TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id,
            'decision' => 'approved', 'decided_by_id' => $this->actor('secretary')->id, 'reason' => 'No puede aprobar']));
        $decision = TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id,
            'decision' => 'approved', 'decided_by_id' => $trip->registered_by_id, 'reason' => 'Aprobación de otro contenido']);
        $this->rejected(function () use ($trip, $decision): void {
            $change = $this->change($trip, ['execution_status' => 'completed', 'officially_completed_at' => CarbonImmutable::now(), 'completed_by_id' => $trip->registered_by_id]);
            TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id, 'validation_type' => 'volume_difference', 'applied_by_id' => $trip->registered_by_id]);
        });
        $this->assertSame('in_progress', $trip->fresh()->execution_status);
        $this->assertNull($decision->fresh()->application);
    }

    public function test_reported_zero_volume_is_preserved_without_claiming_complete_operation(): void
    {
        $trip = $this->ready();
        $load = $this->load($trip, '0.00');
        $this->assertSame('0.00', $load->items->first()->loaded_liters);
        $this->assertSame('pending', $trip->fresh()->execution_status);
        $this->assertNull($trip->fresh()->officially_completed_at);
    }

    public function test_operational_timestamp_preserves_timezone_and_fractional_seconds(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $compartment = $assignment->fleetAsset->tanker->compartments->first();
        $at = CarbonImmutable::parse('2026-10-11T09:10:11.123456-06:00');
        $load = $this->service()->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            [$compartment->id => '250.25'], $at, 'Fecha comunicada Costa Rica');
        $this->assertSame('2026-10-11 15:10:11.123456', $load->occurred_at->utc()->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-10-11 15:10:11.123456', $load->items->first()->occurred_at->utc()->format('Y-m-d H:i:s.u'));
    }
}
