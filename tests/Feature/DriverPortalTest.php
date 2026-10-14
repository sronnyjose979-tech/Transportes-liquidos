<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class DriverPortalTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function prepared(): array
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $trip = $this->trip($this->request($admin));
        $this->ensureAssignments($trip);
        $assignment = $trip->driverAssignments()->current()->firstOrFail();
        $driver = $assignment->employee->user;
        $tractor = $trip->assetAssignments()->current()->where('asset_type', 'tractor_unit')->firstOrFail();
        $this->actingAs($driver);

        return [$admin, $trip, $driver, $tractor, $assignment];
    }

    private function report(array $overrides = []): array
    {
        return $overrides + ['request_key' => (string) Str::uuid(), 'type' => 'incident', 'information' => ['observation' => 'Reporte informativo desde celular'], 'occurred_at' => now()->format('Y-m-d\TH:i:s.uP')];
    }

    private function reading(string $tractor, array $overrides = []): array
    {
        return $overrides + ['request_key' => (string) Str::uuid(), 'tractor_assignment_id' => $tractor, 'reading_type' => 'initial', 'reading_value' => '1000.25', 'reason' => 'Lectura física sintética', 'occurred_at' => now()->format('Y-m-d\TH:i:s.uP')];
    }

    public function test_driver_records_reports_and_exact_readings_once_and_office_can_consult_without_official_transitions(): void
    {
        [$admin, $trip, , $tractor] = $this->prepared();
        $url = '/api/transport/my-trips/'.$trip->id;
        $body = $this->report(['type' => 'completion_report']);
        $event = $this->postJson($url.'/reports', $body)->assertCreated()->json('data.id');
        $this->postJson($url.'/reports', $body)->assertCreated()->assertJsonPath('data.id', $event);
        $this->postJson($url.'/reports', [...$body, 'information' => ['observation' => 'Contenido diferente']])->assertConflict();
        $odometer = $this->reading($tractor->id);
        $reading = $this->postJson($url.'/odometer', $odometer)->assertCreated()->json('data.id');
        $this->postJson($url.'/odometer', $odometer)->assertCreated()->assertJsonPath('data.id', $reading);
        $this->postJson($url.'/odometer', [...$odometer, 'reading_value' => '1000.26'])->assertConflict();
        $this->getJson($url)->assertOk()->assertSee($event)->assertSee($reading)->assertDontSee('storage_key')->assertDontSee('password');
        $this->assertDatabaseCount('trip_events', 1);
        $this->assertDatabaseCount('trip_odometer_readings', 1);
        $this->assertSame('pending', $trip->refresh()->execution_status);
        $this->assertNull($trip->officially_completed_at);
        $this->actingAs($this->actor('secretary'))->getJson('/api/transport/trips/'.$trip->id.'/reports')->assertOk()->assertSee($event);
        $this->getJson('/api/transport/trips/'.$trip->id.'/odometer')->assertOk()->assertSee($reading);
        $this->actingAs($admin)->getJson('/api/transport/trips/'.$trip->id.'/reports')->assertOk()->assertSee($event);
    }

    public function test_driver_cannot_write_another_trip_use_another_tractor_or_execute_official_actions(): void
    {
        [$admin, $trip, $driver, $tractor] = $this->prepared();
        $other = $this->trip($this->request($admin));
        $this->ensureAssignments($other);
        $otherTractor = $other->assetAssignments()->current()->where('asset_type', 'tractor_unit')->firstOrFail();
        $this->actingAs($driver);
        $this->postJson('/api/transport/my-trips/'.$other->id.'/reports', $this->report())->assertForbidden();
        $this->postJson('/api/transport/my-trips/'.$trip->id.'/odometer', $this->reading($otherTractor->id))->assertUnprocessable();
        $this->getJson('/api/transport/my-trips/'.$other->id)->assertNotFound();
        foreach (['start', 'finish', 'cancel', 'close'] as $action) {
            $this->postJson('/api/transport/trips/'.$trip->id.'/'.$action, ['reason' => 'Intento ajeno'])->assertForbidden();
        }
        $reading = $this->postJson('/api/transport/my-trips/'.$trip->id.'/odometer', $this->reading($tractor->id))->assertCreated()->json('data.id');
        $this->postJson('/api/transport/trips/'.$trip->id.'/odometer/'.$reading.'/corrections', $this->reading($tractor->id))->assertForbidden();
        $this->assertSame('pending', $trip->refresh()->execution_status);
        $this->assertDatabaseCount('trip_events', 0);
    }

    public function test_released_assignments_remove_write_access_and_actor_identifiers_cannot_be_forged(): void
    {
        [$admin, $trip, $driver, $tractor] = $this->prepared();
        $url = '/api/transport/my-trips/'.$trip->id;
        $report = $this->report(['created_by_id' => $admin->id, 'actor_id' => $admin->id]);
        $this->postJson($url.'/reports', $report)->assertUnprocessable();
        $committed = $this->report();
        $event = $this->postJson($url.'/reports', $committed)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('trip_events', ['id' => $event, 'created_by_id' => $driver->id]);
        app(AssignmentAdministration::class)->release($admin, $trip->id, 'driver', 'Liberación auditada');
        $this->postJson($url.'/reports', $committed)->assertCreated()->assertJsonPath('data.id', $event);
        $this->postJson($url.'/reports', $this->report())->assertForbidden();
        $this->postJson($url.'/odometer', $this->reading($tractor->id))->assertForbidden();
        $this->getJson($url)->assertNotFound();
        $this->getJson('/api/transport/my-trips')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('trip_events', 1);
    }

    public function test_inactive_users_employees_and_role_revocations_are_enforced_on_server(): void
    {
        [, $trip, $driver, $tractor, $assignment] = $this->prepared();
        $url = '/api/transport/my-trips/'.$trip->id;
        $assignment->employee->update(['is_active' => false]);
        $this->postJson($url.'/reports', $this->report())->assertForbidden();
        $this->postJson($url.'/odometer', $this->reading($tractor->id))->assertForbidden();
        $assignment->employee->update(['is_active' => true]);
        $driver->forceFill(['is_active' => false])->save();
        $this->postJson($url.'/reports', $this->report())->assertForbidden();
        $driver->forceFill(['is_active' => true])->save();
        $driver->roles()->detach();
        $this->actingAs($driver);
        $this->getJson('/api/transport/my-trips')->assertForbidden();
        $this->actingAs($this->actor('mechanic'))->postJson($url.'/reports', $this->report())->assertForbidden();
        $this->assertDatabaseCount('trip_events', 0);
    }

    public function test_invalid_odometer_and_report_inputs_do_not_create_partial_records(): void
    {
        [, $trip, , $tractor] = $this->prepared();
        $url = '/api/transport/my-trips/'.$trip->id;
        foreach (['-1', '1e2', 'NaN'] as $invalid) {
            $this->postJson($url.'/odometer', $this->reading($tractor->id, ['reading_value' => $invalid]))->assertUnprocessable();
        }
        $this->postJson($url.'/reports', $this->report(['occurred_at' => '2026-10-14']))->assertUnprocessable();
        $this->postJson($url.'/reports', $this->report(['type' => 'official_completion']))->assertUnprocessable();
        $this->postJson($url.'/odometer', $this->reading($tractor->id, ['reading_type' => 'correction']))->assertUnprocessable();
        $this->assertDatabaseCount('trip_events', 0);
        $this->assertDatabaseCount('trip_odometer_readings', 0);
    }

    public function test_own_history_is_paginated_after_release_and_does_not_expose_other_actors(): void
    {
        [$admin, $trip, $driver] = $this->prepared();
        $path = '/api/transport/my-trips/'.$trip->id.'/reports';
        $this->postJson($path, $this->report())->assertCreated();
        $this->postJson($path, $this->report())->assertCreated();
        $this->actingAs($admin)->postJson($path, $this->report())->assertCreated();
        app(AssignmentAdministration::class)->release($admin, $trip->id, 'driver', 'Liberación manual para consulta histórica');
        $this->actingAs($driver)->getJson('/api/transport/my-records?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data.reports')->assertJsonPath('data.reports.0.created_by_id', $driver->id)
            ->assertJsonPath('data.meta.last_page', 2);
        $this->getJson('/api/transport/my-records?per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data.reports')->assertJsonPath('data.meta.current_page', 2);
        $this->getJson('/api/transport/my-records?per_page=101')->assertUnprocessable();
    }
}
