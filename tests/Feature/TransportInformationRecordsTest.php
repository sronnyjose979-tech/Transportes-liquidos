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

    public function test_cancelled_trip_closes_without_fictitious_completion_and_preserves_history(): void
    {
        $trip = $this->trip($this->request());
        $cancelActor = $this->actor('secretary');
        $closeActor = $this->actor('administrator');
        $cancellation = $this->change($trip, ['execution_status' => 'cancelled',
            'cancelled_at' => '2026-10-12T12:00:00Z', 'cancelled_by_id' => $cancelActor->id,
            'cancellation_reason' => 'Cliente cancela'], $cancelActor);
        $this->checkConstraints();
        $cancelled = $this->row($trip);
        $closure = $this->change($trip, ['administrative_status' => 'closed',
            'closed_at' => '2026-10-13T12:00:00Z', 'closed_by_id' => $closeActor->id], $closeActor);
        $warning = TripWarning::create(['trip_id' => $trip->id, 'code' => 'pending_information',
            'evidence' => ['detail' => 'Información pendiente'], 'closure_version' => $trip->version]);
        $this->checkConstraints();
        $trip->refresh();
        $this->assertSame('cancelled', $trip->execution_status);
        $this->assertSame('closed', $trip->administrative_status);
        $this->assertNull($trip->officially_started_at);
        $this->assertNull($trip->officially_completed_at);
        $this->assertNull($trip->completed_by_id);
        $this->assertSame($cancelActor->id, $trip->cancelled_by_id);
        $this->assertSame($closeActor->id, $trip->closed_by_id);
        $this->assertSame('2026-10-12T12:00:00+00:00', $trip->cancelled_at->toIso8601String());
        $this->assertSame('2026-10-13T12:00:00+00:00', $trip->closed_at->toIso8601String());
        $this->assertSame('Cliente cancela', $trip->cancellation_reason);
        $this->assertSame($cancelled, $closure->before_values);
        $this->assertSame($cancelActor->id, $cancellation->actor_id);
        $originalAudit = $this->row($cancellation);
        $originalWarning = $this->row($warning);
        $this->change($trip, ['scheduled_start_at' => '2026-10-10T12:00:00Z'], $cancelActor);
        $this->checkConstraints();
        $this->assertSame(3, $trip->changes()->count());
        $this->assertSame($originalAudit, $this->row($cancellation));
        $this->assertSame($originalWarning, $this->row($warning));
        $this->assertNull($trip->fresh()->officially_completed_at);
        $this->assertSame('Agua', $trip->fresh()->product_name_snapshot);
        $this->rejected(fn () => DB::table('trip_warnings')->where('id', $warning->id)->delete());
    }

    public function test_secretary_can_close_trip_cancelled_after_official_start(): void
    {
        $trip = $this->trip($this->request());
        $secretary = $this->actor('secretary');
        $this->change($trip, ['execution_status' => 'in_progress', 'officially_started_at' => '2026-10-11T12:00:00Z',
            'started_by_id' => $trip->registered_by_id]);
        $this->change($trip, ['execution_status' => 'cancelled', 'cancelled_at' => '2026-10-12T12:00:00Z',
            'cancelled_by_id' => $secretary->id, 'cancellation_reason' => 'Cancelación durante viaje'], $secretary);
        $this->change($trip, ['administrative_status' => 'closed', 'closed_at' => '2026-10-12T12:00:00Z',
            'closed_by_id' => $secretary->id], $secretary);
        $this->checkConstraints();
        $this->assertNotNull($trip->fresh()->officially_started_at);
        $this->assertNull($trip->fresh()->officially_completed_at);
        $this->assertTrue($trip->fresh()->closedBy->is($secretary));
        $this->assertSame('cancelled', $trip->fresh()->execution_status);
    }

    public function test_pending_and_in_progress_trips_cannot_close_without_terminal_condition(): void
    {
        foreach (['pending', 'in_progress'] as $status) {
            $trip = $this->trip($this->request());
            if ($status === 'in_progress') {
                $this->change($trip, ['execution_status' => $status, 'officially_started_at' => '2026-10-11T12:00:00Z',
                    'started_by_id' => $trip->registered_by_id]);
                $this->checkConstraints();
            }
            $this->rejected(fn () => $this->change($trip, ['administrative_status' => 'closed',
                'closed_at' => '2026-10-13T12:00:00Z', 'closed_by_id' => $trip->registered_by_id]));
            $this->assertSame('open', $trip->fresh()->administrative_status);
            $this->assertSame($status, $trip->fresh()->execution_status);
        }
    }

    public function test_cancelled_closure_requires_chronology_actor_and_audit(): void
    {
        $trip = $this->trip($this->request());
        $this->change($trip, ['execution_status' => 'cancelled', 'cancelled_at' => '2026-10-12T12:00:00Z',
            'cancelled_by_id' => $trip->registered_by_id, 'cancellation_reason' => 'Cancelado']);
        $this->checkConstraints();
        $this->rejected(fn () => $this->change($trip, ['administrative_status' => 'closed',
            'closed_at' => '2026-10-11T12:00:00Z', 'closed_by_id' => $trip->registered_by_id]));
        $trip->refresh();
        $this->rejected(fn () => $this->change($trip, ['administrative_status' => 'closed', 'closed_at' => '2026-10-13T12:00:00Z']));
        $trip->refresh();
        $driver = $this->actor('driver');
        $this->rejected(fn () => $this->change($trip, ['administrative_status' => 'closed',
            'closed_at' => '2026-10-13T12:00:00Z', 'closed_by_id' => $driver->id]));
        $trip->refresh();
        $this->rejected(fn () => DB::table('trips')->where('id', $trip->id)->update(['administrative_status' => 'closed',
            'closed_at' => '2026-10-13T12:00:00Z', 'closed_by_id' => $trip->registered_by_id, 'version' => $trip->version + 1]));
        $this->assertSame('open', $trip->fresh()->administrative_status);
        $this->assertSame(1, $trip->changes()->count());
    }
}
