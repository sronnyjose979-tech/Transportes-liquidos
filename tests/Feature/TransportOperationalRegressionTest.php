<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use App\Modules\TransportAndDispatch\Models\TransportChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TransportOperationalRegressionTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_old_released_assignments_and_fabricated_start_cannot_satisfy_completion_via_sql(): void
    {
        $trip = $this->trip($this->request());
        $this->ensureAssignments($trip);
        $this->operationalRecords($trip);
        $original = $this->row($trip->tripLoad()->first());
        foreach (['driver', 'tractor_unit', 'tanker'] as $kind) {
            app(AssignmentAdministration::class)->release($trip->registeredBy, $trip->id, $kind, 'Liberación pendiente');
        }
        $this->rejected(function () use ($trip) {
            $before = $this->row($trip);
            $trip->forceFill(['execution_status' => 'completed', 'officially_started_at' => CarbonImmutable::now(), 'started_by_id' => $trip->registered_by_id,
                'officially_completed_at' => CarbonImmutable::now(), 'completed_by_id' => $trip->registered_by_id, 'version' => 2])->save();
            TransportChange::create(['trip_id' => $trip->id, 'action' => 'correction', 'before_values' => $before, 'after_values' => $this->row($trip),
                'from_version' => 1, 'to_version' => 2, 'actor_id' => $trip->registered_by_id, 'reason' => 'Finalización sin inicio oficial auditado']);
        });
        $this->assertSame('pending', $trip->fresh()->execution_status);
        $this->assertSame(0, $trip->driverAssignments()->current()->count());
        $this->assertSame(2, $trip->assetAssignments()->count());
        $this->assertSame($original, $this->row($trip->tripLoad()->first()));
    }

    public function test_direct_sql_unload_item_cannot_reference_load_of_another_trip(): void
    {
        $first = $this->trip($this->request());
        $second = $this->trip($this->request());
        foreach ([$first, $second] as $trip) {
            $this->ensureAssignments($trip);
            $this->operationalRecords($trip);
        }
        $foreign = $first->tripLoad()->first()->items()->first();
        $unload = $second->unload()->first();
        $this->rejected(fn () => DB::table('unload_items')->insert(['id' => (string) Str::uuid(), 'trip_unload_id' => $unload->id, 'load_item_id' => $foreign->id,
            'delivered_liters' => '0', 'recorded_by_id' => $second->registered_by_id, 'occurred_at' => CarbonImmutable::now(), 'reason' => 'Carga de otro viaje']));
        $this->assertSame(1, $unload->items()->count());
        $this->assertNotSame($foreign->id, $unload->items()->first()->load_item_id);
    }
}
