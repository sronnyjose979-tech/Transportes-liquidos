<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Models\TransportChange;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TransportHistoryIntegrityTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_every_update_requires_exact_audit_and_version_in_same_transaction(): void
    {
        $request = $this->request();
        $this->rejected(fn () => DB::table('transport_requests')->where('id', $request->id)->update(['requested_liters' => 100]));
        $this->rejected(fn () => DB::table('transport_requests')->where('id', $request->id)->update(['requested_liters' => 100, 'version' => 2]));
        $this->assertSame('1000.25', $request->fresh()->requested_liters);
        $this->assertSame(0, TransportChange::count());
        $change = $this->change($request, ['requested_liters' => '1200.50']);
        $this->checkConstraints();
        $this->assertSame('1000.25', (string) $change->before_values['requested_liters']);
        $this->assertEquals(1200.5, $change->after_values['requested_liters']);
        $this->assertSame(2, $request->fresh()->version);
        $this->assertTrue($change->transportRequest->is($request));
        $this->assertTrue($change->actor->is($request->registeredBy));
    }

    public function test_closed_trip_can_be_corrected_by_secretary_with_immutable_audit(): void
    {
        $trip = $this->closedTrip();
        $secretary = $this->actor('secretary');
        $change = $this->change($trip, ['scheduled_start_at' => '2026-10-10T12:00:00Z'], $secretary);
        $this->checkConstraints();
        $this->assertSame('closed', $trip->fresh()->administrative_status);
        $this->assertSame(3, $trip->changes()->count());
        $this->rejected(fn () => DB::table('transport_changes')->where('id', $change->id)->update(['reason' => 'Rewrite']));
        $this->rejected(fn () => DB::table('transport_changes')->where('id', $change->id)->delete());
        $this->expectException(LogicException::class);
        $change->update(['reason' => 'Rewrite']);
    }

    public function test_forged_audit_payload_cannot_validate_a_change(): void
    {
        $request = $this->request();
        $this->rejected(function () use ($request): void {
            $change = $this->change($request, ['requested_liters' => '1300']);
            // Immutable row itself cannot be patched to cover a different correction.
            DB::table('transport_changes')->where('id', $change->id)->update(['before_values' => '{}']);
        });
        $this->assertSame('1000.25', $request->fresh()->requested_liters);
    }

    public function test_history_truncation_and_root_identity_changes_are_denied(): void
    {
        $request = $this->request();
        $this->rejected(fn () => DB::statement('TRUNCATE transport_changes CASCADE'));
        $this->rejected(fn () => $this->change($request, ['registered_by_id' => $this->actor()->id]));
        $this->assertSame(1, $request->fresh()->version);
    }

    public function test_full_audit_cannot_fabricate_previous_values_or_be_inserted_without_update(): void
    {
        $request = $this->request();
        $this->rejected(function () use ($request): void {
            $before = $this->row($request);
            $request->forceFill(['requested_liters' => '1400', 'version' => 2])->save();
            $before['requested_liters'] = 999;
            TransportChange::create(['transport_request_id' => $request->id, 'action' => 'correction', 'before_values' => $before,
                'after_values' => $this->row($request), 'from_version' => 1, 'to_version' => 2, 'actor_id' => $request->registered_by_id, 'reason' => 'Historia falsa']);
        });
        $request->refresh();
        $this->rejected(fn () => TransportChange::create(['transport_request_id' => $request->id, 'action' => 'correction',
            'before_values' => $this->row($request), 'after_values' => $this->row($request), 'from_version' => 1, 'to_version' => 2,
            'actor_id' => $request->registered_by_id, 'reason' => 'Sin cambio']));
        $this->assertSame(1, $request->fresh()->version);
        $this->assertSame(0, TransportChange::count());
    }
}
