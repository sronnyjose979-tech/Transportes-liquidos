<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Application\TripOperations;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationDecision;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationRequest;
use App\Modules\TransportAndDispatch\Models\TransportChange;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripUnload;
use App\Modules\TransportAndDispatch\Models\UnloadItem;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TripCompletionTest extends PostgresTestCase
{
    use TransportFixtures;

    private function service(): TripOperations
    {
        return app(TripOperations::class);
    }

    private function ready(bool $started = true): Trip
    {
        $trip = $this->trip($this->request());
        $this->ensureAssignments($trip);

        return $started ? $this->service()->start($trip->registeredBy, $trip->id, 'Inicio oficial') : $trip;
    }

    private function invalid(callable $write): void
    {
        try {
            $write();
            $this->fail('Finalización debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function decision(Trip $trip): TransportAuthorizationDecision
    {
        $content = json_decode(DB::selectOne('SELECT trip_volume_content(?)::text AS content', [$trip->id])->content, true);
        $request = TransportAuthorizationRequest::create(['trip_id' => $trip->id, 'type' => 'volume_difference', 'content' => $content,
            'target_version' => $trip->fresh()->version, 'requested_by_id' => $this->actor('secretary')->id, 'reason' => 'Valores exactos comunicados']);

        return TransportAuthorizationDecision::create(['authorization_request_id' => $request->id, 'decision' => 'approved',
            'decided_by_id' => $trip->registered_by_id, 'reason' => 'Administrador autoriza diferencia']);
    }

    public function test_secretary_can_complete_without_driver_report_or_extra_evidence_and_resources_remain(): void
    {
        $trip = $this->ready();
        $this->operationalRecords($trip);
        $load = $trip->tripLoad()->first();
        $before = $this->row($load);
        $secretary = $this->actor('secretary');
        $finished = $this->service()->finish($secretary, $trip->id, 'Confirmación oficial');
        $this->assertSame('completed', $finished->execution_status);
        $this->assertSame('open', $finished->administrative_status);
        $this->assertSame($secretary->id, $finished->completed_by_id);
        $this->assertNotNull($finished->officially_completed_at);
        $this->assertSame(0, $finished->events()->count());
        $this->assertSame($before, $this->row($load));
        $this->assertSame(1, $finished->driverAssignments()->current()->count());
        $this->assertSame(2, $finished->assetAssignments()->current()->count());
        $this->assertSame('official_completion', $finished->changes()->where('to_version', $finished->version)->first()->action);
        $this->invalid(fn () => $this->service()->finish($secretary, $trip->id, 'Duplicada'));
        $this->assertSame(2, $finished->changes()->count());
    }

    public function test_missing_start_or_operations_cannot_complete_in_application_or_sql(): void
    {
        $pending = $this->ready(false);
        $this->invalid(fn () => $this->service()->finish($pending->registeredBy, $pending->id, 'Sin inicio'));
        $trip = $this->ready();
        $this->invalid(fn () => $this->service()->finish($trip->registeredBy, $trip->id, 'Sin operaciones'));
        // Bypass fixture helpers: direct audited SQL still cannot bypass D02.
        $this->rejected(function () use ($trip): void {
            $before = $this->row($trip);
            DB::table('trips')->where('id', $trip->id)->update(['execution_status' => 'completed', 'completed_by_id' => $trip->registered_by_id,
                'officially_completed_at' => CarbonImmutable::now(), 'version' => $trip->version + 1]);
            TransportChange::create(['trip_id' => $trip->id, 'action' => 'official_completion',
                'before_values' => $before, 'after_values' => $this->row($trip), 'from_version' => $trip->version,
                'to_version' => $trip->version + 1, 'actor_id' => $trip->registered_by_id, 'reason' => 'Intento SQL sin operaciones']);
        });
        $this->assertSame('in_progress', $trip->fresh()->execution_status);
        $this->assertSame(1, $trip->changes()->count());
    }

    public function test_every_load_detail_requires_unload_even_when_delivery_is_zero(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $compartment = $assignment->fleetAsset->tanker->compartments->first();
        $load = $this->service()->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            [$compartment->id => '100.00'], CarbonImmutable::now(), 'Carga');
        $unload = TripUnload::create(['trip_id' => $trip->id, 'trip_load_id' => $load->id,
            'operation_site_id' => $trip->transportRequest->route->destination_site_id, 'recorded_by_id' => $trip->registered_by_id,
            'occurred_at' => CarbonImmutable::now(), 'reason' => 'Cabecera sin cantidades']);
        $this->invalid(fn () => $this->service()->finish($trip->registeredBy, $trip->id, 'Falta detalle'));
        UnloadItem::create(['trip_unload_id' => $unload->id, 'load_item_id' => $load->items->first()->id, 'delivered_liters' => '0.00',
            'recorded_by_id' => $trip->registered_by_id, 'occurred_at' => CarbonImmutable::now(), 'reason' => 'Cero comunicado']);
        $this->invalid(fn () => $this->service()->finish($trip->registeredBy, $trip->id, 'Falta autorización'));
        $decision = $this->decision($trip);
        $finished = $this->service()->finish($this->actor('secretary'), $trip->id, 'Confirmación con autorización', $decision->id);
        $this->assertSame('completed', $finished->execution_status);
        $this->assertSame('0.00', $unload->items()->first()->delivered_liters);
        $this->assertNotNull($decision->fresh()->application);
        $this->assertSame('volume_difference', $decision->fresh()->application->validation_type);
        $this->assertSame(1, $finished->driverAssignments()->current()->count());
    }

    public function test_delivery_capacity_uses_snapshot_and_rejection_leaves_no_partial_unload(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $compartment = $assignment->fleetAsset->tanker->compartments->first();
        $load = $this->service()->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            [$compartment->id => '100.00'], CarbonImmutable::now(), 'Carga');
        $compartment->update(['capacity_liters' => '2000']);
        $this->invalid(fn () => $this->service()->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items->first()->id => '1000.01'], CarbonImmutable::now(), 'Excede capacidad histórica'));
        $this->assertSame(0, $trip->unload()->count());
        $this->assertSame('1000.00', $load->items->first()->fresh()->capacity_liters_snapshot);
    }

    public function test_zero_loaded_and_delivered_can_complete_without_inventing_missing_evidence(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $load = $this->service()->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            [$assignment->fleetAsset->tanker->compartments->first()->id => '0.00'], CarbonImmutable::now(), 'Cero real registrado');
        $this->service()->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items->first()->id => '0.00'], CarbonImmutable::now(), 'Cero real descargado');
        $this->assertNull(DB::selectOne('SELECT trip_volume_content(?)::text AS content', [$trip->id])->content);
        $finished = $this->service()->finish($trip->registeredBy, $trip->id, 'Confirmación oficial');
        $this->assertSame('completed', $finished->execution_status);
        $this->assertSame(2, $finished->assetAssignments()->current()->count());
    }

    public function test_driver_and_revoked_office_cannot_finalize(): void
    {
        $trip = $this->ready();
        $this->operationalRecords($trip);
        $driver = $trip->driverAssignments()->current()->first()->employee->user;
        foreach ([$driver, $this->actor('mechanic'), $this->actor('secretary', false)] as $actor) {
            try {
                $this->service()->finish($actor, $trip->id, 'No autorizado');
                $this->fail('Actor no autorizado.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('in_progress', $trip->fresh()->execution_status);
            }
        }
        $this->assertSame(1, $trip->changes()->count());
    }

    public function test_wrong_or_stale_decision_rolls_back_official_completion_and_audit(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $load = $this->service()->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            [$assignment->fleetAsset->tanker->compartments->first()->id => '100'], CarbonImmutable::now(), 'Carga');
        $this->service()->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items->first()->id => '90'], CarbonImmutable::now(), 'Descarga');
        $decision = $this->decision($trip);
        $this->change($trip, ['planning_status' => 'rescheduled']);
        $this->checkConstraints();
        $before = $this->row($trip);
        $this->invalid(fn () => $this->service()->finish($trip->registeredBy, $trip->id, 'Decisión obsoleta', $decision->id));
        $this->assertSame($before, $this->row($trip));
        $this->assertNull($decision->fresh()->application);
        $this->assertSame(2, $trip->changes()->count());
    }
}
