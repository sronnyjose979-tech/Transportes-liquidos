<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Models\TripEvent;
use App\Modules\TransportAndDispatch\Models\TripWarning;
use App\Modules\TransportAndDispatch\Models\TripWarningResolution;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TransportInformationRecordsTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_completion_report_is_informative_and_never_changes_official_states(): void
    {
        $trip = $this->trip($this->request());
        $event = TripEvent::create(['trip_id' => $trip->id, 'type' => 'completion_report', 'information' => ['report' => 'Conductor comunica término'],
            'created_by_id' => $trip->registered_by_id, 'occurred_at' => '2026-10-12T12:00:00Z']);
        $this->assertTrue($event->trip->is($trip));
        $this->assertTrue($event->createdBy->is($trip->registeredBy));
        $this->assertSame('pending', $trip->fresh()->execution_status);
        $this->assertSame('open', $trip->fresh()->administrative_status);
        $this->assertNull($trip->fresh()->officially_completed_at);
        $this->rejected(fn () => DB::table('trip_events')->where('id', $event->id)->update(['information' => '{}']));
        $this->rejected(fn () => DB::table('trip_events')->where('id', $event->id)->delete());
    }

    public function test_closure_preserves_pending_evidence_after_resolution_and_correction(): void
    {
        $trip = $this->closedTrip();
        $warning = TripWarning::create(['trip_id' => $trip->id, 'code' => 'pending_information', 'evidence' => ['detail' => 'Información pendiente'], 'closure_version' => $trip->version]);
        $resolution = TripWarningResolution::create(['warning_id' => $warning->id, 'action' => 'information_received',
            'evidence' => ['detail' => 'Información recibida'], 'actor_id' => $this->actor('secretary')->id, 'reason' => 'Entrega posterior']);
        $this->change($trip, ['scheduled_start_at' => '2026-10-10T12:00:00Z']);
        $this->checkConstraints();
        $this->assertSame('Información pendiente', $warning->fresh()->evidence['detail']);
        $this->assertSame(2, $warning->closure_version);
        $this->assertTrue($resolution->warning->is($warning));
        $this->assertSame(1, $warning->resolutions()->count());
        $this->assertSame('closed', $trip->fresh()->administrative_status);
        foreach ([$warning, $resolution] as $record) {
            $this->rejected(fn () => DB::table($record->getTable())->where('id', $record->id)->delete());
        }
    }

    public function test_warning_requires_real_closure_and_informative_event_types_are_limited(): void
    {
        $trip = $this->trip($this->request());
        $this->rejected(fn () => TripWarning::create(['trip_id' => $trip->id, 'code' => 'pending', 'evidence' => ['pending' => true], 'closure_version' => 1]));
        $this->rejected(fn () => TripEvent::create(['trip_id' => $trip->id, 'type' => 'official_start', 'information' => ['data' => true], 'created_by_id' => $trip->registered_by_id, 'occurred_at' => '2026-10-12']));
    }

    public function test_unassigned_driver_cannot_record_private_trip_information(): void
    {
        $trip = $this->trip($this->request());
        $driver = $this->actor('driver');
        $this->rejected(fn () => TripEvent::create(['trip_id' => $trip->id, 'type' => 'information', 'information' => ['report' => 'Sin asignación'], 'created_by_id' => $driver->id, 'occurred_at' => '2026-10-12']));
    }
}
