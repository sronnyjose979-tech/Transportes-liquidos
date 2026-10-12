<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Models\Compartment;
use App\Modules\TransportAndDispatch\Application\TripOperations;
use App\Modules\TransportAndDispatch\Models\Trip;
use Carbon\CarbonImmutable;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TransportPartialCaptureTest extends PostgresTestCase
{
    use TransportFixtures;

    private function capture(): array
    {
        $trip = $this->trip($this->request());
        $this->ensureAssignments($trip);
        $trip = app(TripOperations::class)->start($trip->registeredBy, $trip->id, 'Inicio');
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $first = $assignment->fleetAsset->tanker->compartments->first();
        $assignment->fleetAsset->tanker->update(['has_physical_partitions' => true]);
        $second = Compartment::create(['tanker_id' => $assignment->fleet_asset_id, 'code' => 'B', 'capacity_liters' => '1000', 'is_active' => true]);
        $load = app(TripOperations::class)->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            [$first->id => '100.00'], CarbonImmutable::now(), 'Carga parcial');
        $this->actingAs($this->actor('secretary'));

        return [$trip, $load, $second];
    }

    private function payload(string $key, string $id, string $liters = '100.00'): array
    {
        return ['occurred_at' => '2026-10-12T08:00:00-06:00', 'reason' => 'Detalle omitido comunicado', 'evidence' => ['source' => 'Conductor'], 'items' => [[$key => $id, 'liters' => $liters]]];
    }

    private function path(Trip $trip, string $suffix): string
    {
        return '/api/transport/trips/'.$trip->id.'/'.$suffix;
    }

    public function test_missing_load_and_unload_details_append_without_rewriting_history_and_allow_completion(): void
    {
        [$trip, $load, $second] = $this->capture();
        $header = $this->row($load);
        $original = $this->row($load->items[0]);
        $this->postJson($this->path($trip, 'load/items'), $this->payload('compartment_id', $second->id))->assertCreated()->assertJsonCount(2, 'data.items');
        $this->assertSame($header, $this->row($load));
        $this->assertSame($original, $this->row($load->items[0]));
        $added = $load->items()->where('compartment_id', $second->id)->first();
        $this->assertSame(auth()->id(), $added->recorded_by_id);
        $this->assertSame('Detalle omitido comunicado', $added->reason);
        $this->assertSame(['source' => 'Conductor'], $added->evidence);
        $this->assertNotNull($added->registered_at);
        $unload = app(TripOperations::class)->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items[0]->id => '100.00'], CarbonImmutable::now(), 'Descarga parcial');
        $unloadHeader = $this->row($unload);
        $firstUnload = $this->row($unload->items[0]);
        $this->postJson($this->path($trip, 'finish'), ['version' => 2, 'reason' => 'Incompleto'])->assertUnprocessable();
        $this->postJson($this->path($trip, 'unload/items'), $this->payload('load_item_id', $added->id))->assertCreated()->assertJsonCount(2, 'data.items');
        $this->assertSame($unloadHeader, $this->row($unload));
        $this->assertSame($firstUnload, $this->row($unload->items[0]));
        $this->postJson($this->path($trip, 'finish'), ['version' => 2, 'reason' => 'Completo'])->assertOk();
        $this->postJson($this->path($trip, 'load/items'), $this->payload('compartment_id', $second->id))->assertUnprocessable();
        $this->assertSame(1, $trip->driverAssignments()->current()->count());
        $this->assertSame(2, $trip->assetAssignments()->current()->count());
    }

    public function test_append_rejects_duplicates_capacity_foreign_details_actor_spoofing_and_unauthorized_users(): void
    {
        [$trip, $load, $second] = $this->capture();
        $path = $this->path($trip, 'load/items');
        $this->postJson($path, $this->payload('compartment_id', $second->id, '1000.01'))->assertUnprocessable();
        $batch = $this->payload('compartment_id', $second->id);
        $batch['items'][] = ['compartment_id' => $load->items[0]->compartment_id, 'liters' => '1'];
        $this->postJson($path, $batch)->assertUnprocessable();
        $this->assertSame(1, $load->items()->count());
        $this->postJson($path, $this->payload('compartment_id', $second->id) + ['recorded_by_id' => $trip->registered_by_id])->assertUnprocessable();
        $this->postJson($path, $this->payload('compartment_id', $second->id) + ['operation_site_id' => $trip->transportRequest->route->origin_site_id])->assertUnprocessable();
        $this->actingAs($this->actor('driver'))->postJson($path, $this->payload('compartment_id', $second->id))->assertForbidden();
        $this->actingAs($trip->registeredBy)->postJson($path, $this->payload('compartment_id', $second->id))->assertCreated();
        $this->postJson($path, $this->payload('compartment_id', $second->id))->assertUnprocessable();
        $this->assertSame(2, $load->items()->count());
        $other = $this->trip($this->request());
        $this->ensureAssignments($other);
        $this->operationalRecords($other);
        app(TripOperations::class)->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items[0]->id => '100'], CarbonImmutable::now(), 'Descarga parcial');
        $this->postJson($this->path($trip, 'unload/items'), $this->payload('load_item_id', $other->tripLoad->items[0]->id))->assertUnprocessable();
        $this->assertSame(1, $trip->unload->items()->count());
    }

    public function test_append_invalidates_previous_exact_volume_approval_without_consuming_it(): void
    {
        [$trip, $load, $second] = $this->capture();
        $unload = app(TripOperations::class)->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items[0]->id => '90'], CarbonImmutable::now(), 'Diferencia');
        $intent = $this->postJson($this->path($trip, 'authorizations'), ['type' => 'volume_difference', 'reason' => 'Diferencia inicial'])->assertCreated()->json('data');
        $this->actingAs($trip->registeredBy);
        $decision = $this->postJson('/api/transport/authorizations/'.$intent['id'].'/decisions', ['decision' => 'approved', 'reason' => 'Valores iniciales'])->assertCreated()->json('data');
        $this->postJson($this->path($trip, 'load/items'), $this->payload('compartment_id', $second->id))->assertCreated();
        $added = $load->items()->where('compartment_id', $second->id)->first();
        $this->postJson($this->path($trip, 'unload/items'), $this->payload('load_item_id', $added->id))->assertCreated();
        $this->postJson($this->path($trip, 'finish'), ['version' => 2, 'reason' => 'Datos distintos', 'decision_id' => $decision['id']])->assertUnprocessable();
        $this->assertSame('in_progress', $trip->fresh()->execution_status);
        $this->getJson('/api/transport/authorizations/'.$intent['id'])->assertOk()->assertJsonPath('data.decision.application', null);
    }
}
