<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Application\TripOperations;
use App\Modules\TransportAndDispatch\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TransportApiTest extends PostgresTestCase
{
    use TransportFixtures;

    private function url(Trip $trip, string $suffix = ''): string
    {
        return '/api/transport/trips/'.$trip->id.$suffix;
    }

    private function ready(bool $started = false): Trip
    {
        $trip = $this->trip($this->request());
        $this->ensureAssignments($trip);

        return $started ? app(TripOperations::class)->start($trip->registeredBy, $trip->id, 'Inicio') : $trip;
    }

    private function quantities(Trip $trip, string $delivered = '100.00'): void
    {
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $load = $this->postJson($this->url($trip, '/load'), ['operation_site_id' => $trip->transportRequest->route->origin_site_id,
            'occurred_at' => '2026-10-12T08:00:00-06:00', 'reason' => 'Carga informada', 'evidence' => ['source' => 'Conductor'],
            'items' => [['compartment_id' => $assignment->fleetAsset->tanker->compartments->first()->id, 'liters' => '100.00']]])->assertCreated()->json('data');
        $this->postJson($this->url($trip, '/unload'), ['operation_site_id' => $trip->transportRequest->route->destination_site_id,
            'occurred_at' => '2026-10-12T09:00:00-06:00', 'reason' => 'Descarga informada',
            'items' => [['load_item_id' => $load['items'][0]['id'], 'liters' => $delivered]]])->assertCreated();
    }

    public function test_private_api_requires_session_and_office_permissions_and_denies_revoked_accounts(): void
    {
        $this->getJson('/api/transport/requests')->assertUnauthorized();
        foreach (['driver', 'mechanic'] as $role) {
            $this->actingAs($this->actor($role))->getJson('/api/transport/trips')->assertForbidden();
        }
        $office = $this->actor('secretary');
        $this->actingAs($office)->getJson('/api/transport/trips')->assertOk();
        $office->roles()->detach();
        $this->getJson('/api/transport/trips')->assertForbidden();
    }

    public function test_request_create_update_audit_pagination_and_stale_version(): void
    {
        $seed = $this->request();
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary);
        $data = ['customer_id' => $seed->customer_id, 'liquid_product_id' => $seed->liquid_product_id, 'route_id' => $seed->route_id,
            'requested_liters' => '1000.25', 'requested_at' => '2026-10-12T08:00:00-06:00'];
        $request = $this->postJson('/api/transport/requests', $data)->assertCreated()->assertJsonPath('data.registered_by_id', $secretary->id)->assertJsonPath('data.requested_at', '2026-10-12T14:00:00.000000Z')->json('data');
        $path = '/api/transport/requests/'.$request['id'];
        $this->patchJson($path, ['version' => 1, 'requested_liters' => '1250.50', 'reason' => 'Cliente aumenta'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($path, ['version' => 1, 'requested_liters' => '1500', 'reason' => 'Obsoleta'])->assertConflict();
        $this->getJson($path.'/history')->assertOk()->assertJsonPath('data.0.before_values.requested_liters', 1000.25)->assertJsonPath('data.0.actor_id', $secretary->id);
        $this->getJson('/api/transport/requests?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->postJson('/api/transport/requests', $data + ['registered_by_id' => $seed->registered_by_id])->assertUnprocessable()->assertJsonValidationErrors('registered_by_id');
        $this->postJson('/api/transport/requests', array_replace($data, ['requested_liters' => '1.234']))->assertUnprocessable();
        $this->postJson('/api/transport/requests', array_replace($data, ['customer_id' => (string) Str::uuid()]))->assertUnprocessable();
        $this->patchJson($path, ['version' => 2, 'liquid_product_id' => $seed->liquid_product_id, 'reason' => 'Producto'])->assertUnprocessable();
        $this->getJson('/api/transport/requests/'.Str::uuid())->assertNotFound();
        $this->deleteJson($path)->assertMethodNotAllowed();
    }

    public function test_trip_create_program_reprogram_and_official_start_are_audited(): void
    {
        $request = $this->request();
        $this->actingAs($request->registeredBy);
        $tripData = $this->postJson('/api/transport/trips', ['transport_request_id' => $request->id])->assertCreated()->assertJsonPath('data.product_name_snapshot', 'Agua')->json('data');
        $trip = Trip::findOrFail($tripData['id']);
        $this->postJson($this->url($trip, '/schedule'), ['version' => 1, 'reason' => 'Horario', 'scheduled_start_at' => '2026-10-13T08:00:00-06:00', 'scheduled_end_at' => '2026-10-13T09:00:00-06:00'])->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($this->url($trip, '/reschedule'), ['version' => 2, 'reason' => 'Cambio', 'scheduled_start_at' => '2026-10-14T08:00:00-06:00'])->assertOk()->assertJsonPath('data.planning_status', 'rescheduled');
        $this->postJson($this->url($trip, '/start'), ['version' => 3, 'reason' => 'Sin asignaciones'])->assertUnprocessable();
        $this->ensureAssignments($trip);
        $this->postJson($this->url($trip, '/start'), ['version' => 3, 'reason' => 'Inicio'])->assertOk()->assertJsonPath('data.execution_status', 'in_progress');
        $this->postJson($this->url($trip, '/reschedule'), ['version' => 4, 'reason' => 'No permitido', 'scheduled_start_at' => '2026-10-15T00:00:00Z'])->assertUnprocessable();
        $this->getJson($this->url($trip))->assertOk()->assertJsonPath('data.request.id', $request->id)->assertJsonCount(2, 'data.current_asset_assignments');
        $this->getJson($this->url($trip, '/history'))->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/transport/trips?transport_request_id='.$request->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_equal_volumes_finalize_and_close_with_warnings_without_releasing_resources(): void
    {
        $trip = $this->ready(true);
        $this->actingAs($this->actor('secretary'));
        $this->quantities($trip);
        $this->getJson($this->url($trip, '/load'))->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson($this->url($trip, '/volume-differences'))->assertOk()->assertJsonPath('data.difference', null);
        $this->postJson($this->url($trip, '/finish'), ['version' => 2, 'reason' => 'Confirmación'])->assertOk()->assertJsonPath('data.execution_status', 'completed');
        $this->postJson($this->url($trip, '/close'), ['version' => 3, 'reason' => 'Revisado', 'warnings' => [['code' => 'pending_information', 'evidence' => ['detail' => 'Comprobante pendiente']]]])->assertOk()->assertJsonPath('data.administrative_status', 'closed');
        $this->getJson($this->url($trip, '/warnings'))->assertOk()->assertJsonPath('data.0.evidence.detail', 'Comprobante pendiente');
        $this->assertSame(1, $trip->driverAssignments()->current()->count());
        $this->assertSame(2, $trip->assetAssignments()->current()->count());
        $this->postJson($this->url($trip, '/assignments/release'), ['resource_kind' => 'driver', 'reason' => 'Liberación manual'])->assertCreated();
        $this->assertSame(0, $trip->driverAssignments()->current()->count());
        $this->assertSame(2, $trip->assetAssignments()->current()->count());
    }

    public function test_volume_difference_requires_admin_exact_decision_and_no_reuse(): void
    {
        $trip = $this->ready(true);
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary);
        $this->quantities($trip, '0.00');
        $this->postJson($this->url($trip, '/finish'), ['version' => 2, 'reason' => 'Sin autorización'])->assertUnprocessable();
        $intent = $this->postJson($this->url($trip, '/authorizations'), ['type' => 'volume_difference', 'reason' => 'Solicitar revisión'])->assertCreated()->assertJsonPath('data.requested_by_id', $secretary->id)->json('data');
        $decisionPath = '/api/transport/authorizations/'.$intent['id'].'/decisions';
        $this->postJson($decisionPath, ['decision' => 'approved', 'reason' => 'No puede'])->assertForbidden();
        $this->actingAs($trip->registeredBy);
        $decision = $this->postJson($decisionPath, ['decision' => 'approved', 'reason' => 'Autoriza valores reales'])->assertCreated()->json('data');
        $this->postJson($decisionPath, ['decision' => 'approved', 'reason' => 'Repetida'])->assertConflict();
        $this->actingAs($secretary);
        $this->postJson($this->url($trip, '/finish'), ['version' => 2, 'reason' => 'Confirmación', 'decision_id' => $decision['id']])->assertOk();
        $this->getJson('/api/transport/authorizations/'.$intent['id'])->assertOk()->assertJsonPath('data.decision.application.validation_type', 'volume_difference');
        $other = $this->ready(true);
        $this->quantities($other, '0.00');
        $this->postJson($this->url($other, '/finish'), ['version' => 2, 'reason' => 'Decisión ajena', 'decision_id' => $decision['id']])->assertUnprocessable();
        $this->assertSame('in_progress', $other->fresh()->execution_status);
        $this->getJson($this->url($trip, '/authorizations'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cancellation_can_close_without_fictitious_completion_and_pending_cannot_close(): void
    {
        $trip = $this->ready();
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary);
        $this->postJson($this->url($trip, '/close'), ['version' => 1, 'reason' => 'No terminal'])->assertUnprocessable();
        $this->postJson($this->url($trip, '/cancel'), ['version' => 1, 'reason' => 'Cliente cancela'])->assertOk()->assertJsonPath('data.cancelled_by_id', $secretary->id);
        $this->postJson($this->url($trip, '/close'), ['version' => 2, 'reason' => 'Revisión administrativa'])->assertOk()->assertJsonPath('data.officially_completed_at', null);
        $this->assertSame(2, $trip->assetAssignments()->current()->count());
        $this->assertSame('Cliente cancela', $trip->fresh()->cancellation_reason);
    }

    public function test_assignment_conflicts_persist_and_admin_can_authorize_exact_operation(): void
    {
        $occupied = $this->ready();
        $second = $this->trip($this->request());
        $driver = $occupied->driverAssignments()->current()->first()->employee;
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary);
        $payload = ['resource_kind' => 'driver', 'resource_id' => $driver->id, 'reason' => 'Asignación'];
        $this->getJson('/api/transport/availability?resource_kind=driver&resource_id='.$driver->id)->assertOk()->assertJsonPath('data.available', false)->assertJsonCount(1, 'data.conflicts');
        $this->postJson($this->url($second, '/assignments/assign'), $payload)->assertConflict();
        $this->getJson($this->url($second, '/assignments'))->assertOk()->assertJsonPath('data.0.action', 'conflict_detected');
        $intent = $this->postJson($this->url($second, '/authorizations'), ['type' => 'assignment_conflict', 'reason' => 'Excepción',
            'content' => ['action' => 'assign', 'resource_kind' => 'driver', 'resource_id' => $driver->id]])->assertCreated()->json('data');
        $this->actingAs($occupied->registeredBy);
        $decision = $this->postJson('/api/transport/authorizations/'.$intent['id'].'/decisions', ['decision' => 'approved', 'reason' => 'Administrador confirma'])->assertCreated()->json('data');
        $this->actingAs($secretary);
        $this->postJson($this->url($second, '/assignments/assign'), $payload + ['decisions' => [$decision['id']]])->assertCreated();
        $this->assertSame(1, $second->driverAssignments()->current()->count());
        $this->postJson($this->url($second, '/schedule'), ['version' => 1, 'reason' => 'Misma ocupación autorizada', 'scheduled_start_at' => '2026-10-13T00:00:00Z'])->assertOk();
        $this->postJson($this->url($second, '/assignments/release'), ['resource_kind' => 'driver', 'reason' => 'Manual'])->assertCreated();
        $this->getJson($this->url($second, '/assignments'))->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_driver_report_is_owned_and_cannot_finalize_or_access_office_views(): void
    {
        $trip = $this->ready();
        $other = $this->ready();
        $driver = $trip->driverAssignments()->current()->first()->employee->user;
        $this->actingAs($driver);
        $report = ['type' => 'completion_report', 'information' => ['report' => 'Terminé'], 'occurred_at' => '2026-10-12T08:00:00-06:00'];
        $this->postJson($this->url($trip, '/reports'), $report)->assertCreated()->assertJsonPath('data.created_by_id', $driver->id);
        $this->postJson($this->url($other, '/reports'), $report)->assertForbidden();
        $this->postJson($this->url($trip, '/reports'), $report + ['created_by_id' => $trip->registered_by_id])->assertUnprocessable();
        $this->postJson($this->url($trip, '/start'), ['version' => 1, 'reason' => 'Conductor'])->assertForbidden();
        $this->getJson($this->url($trip))->assertForbidden();
        $this->assertSame('pending', $trip->fresh()->execution_status);
        $this->actingAs($trip->registeredBy)->postJson($this->url($trip, '/assignments/release'), ['resource_kind' => 'driver', 'reason' => 'Revoca'])->assertCreated();
        $this->actingAs($driver)->postJson($this->url($trip, '/reports'), $report)->assertForbidden();
    }

    public function test_odometer_revision_distance_and_foreign_trip_correction_are_scoped(): void
    {
        $trip = $this->ready();
        $other = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tractor_unit')->first();
        $this->actingAs($this->actor('secretary'));
        $base = ['tractor_assignment_id' => $assignment->id, 'occurred_at' => '2026-10-12T08:00:00Z', 'reason' => 'Lectura'];
        $this->postJson($this->url($trip, '/odometer'), $base + ['reading_type' => 'initial', 'reading_value' => '1000.125'])->assertCreated();
        $final = $this->postJson($this->url($trip, '/odometer'), $base + ['reading_type' => 'final', 'reading_value' => '1050.625'])->assertCreated()->json('data');
        $this->getJson($this->url($trip, '/distance?tractor_assignment_id='.$assignment->id))->assertOk()->assertJsonPath('data.distance', '50.500');
        $correction = ['reading_value' => '1051.125', 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Corrección'];
        $this->postJson($this->url($other, '/odometer/'.$final['id'].'/corrections'), $correction)->assertNotFound();
        $this->postJson($this->url($trip, '/odometer/'.$final['id'].'/corrections'), $correction)->assertCreated()->assertJsonPath('data.revision', 2);
        $this->getJson($this->url($trip, '/distance?tractor_assignment_id='.$assignment->id))->assertOk()->assertJsonPath('data.distance', '51.000');
        $this->getJson($this->url($trip, '/odometer'))->assertOk()->assertJsonCount(3, 'data');
        $this->postJson($this->url($trip, '/odometer/'.$final['id'].'/corrections'), $correction)->assertUnprocessable();
    }

    public function test_active_substitutions_require_exact_admin_decisions_and_tanker_remains_blocked(): void
    {
        foreach (['driver', 'tractor_unit'] as $kind) {
            $trip = $this->ready(true);
            $replacement = $this->assignmentResources()[$kind];
            $this->actingAs($this->actor('secretary'));
            $payload = ['resource_kind' => $kind, 'resource_id' => $replacement->id, 'reason' => 'Sustitución'];
            $this->postJson($this->url($trip, '/assignments/substitute'), $payload)->assertUnprocessable();
            $type = $kind === 'driver' ? 'driver_substitution' : 'tractor_substitution';
            $intent = $this->postJson($this->url($trip, '/authorizations'), ['type' => $type, 'reason' => 'Solicitar',
                'content' => ['action' => 'substitute', 'resource_kind' => $kind, 'resource_id' => $replacement->id]])->assertCreated()->json('data');
            $this->actingAs($trip->registeredBy);
            $decision = $this->postJson('/api/transport/authorizations/'.$intent['id'].'/decisions', ['decision' => 'approved', 'reason' => 'Administrador autoriza'])->assertCreated()->json('data');
            $this->actingAs($this->actor('secretary'))->postJson($this->url($trip, '/assignments/substitute'), $payload + ['decisions' => [$decision['id']]])->assertCreated();
            $this->assertSame(1, $trip->driverAssignments()->current()->count());
            $this->assertSame(2, $trip->assetAssignments()->current()->count());
            $this->getJson($this->url($trip, '/assignments'))->assertOk()->assertJsonCount(4, 'data');
        }
        $tank = $this->assignmentResources()['tanker'];
        $this->postJson($this->url($trip, '/assignments/substitute'), ['resource_kind' => 'tanker', 'resource_id' => $tank->id, 'reason' => 'D05 pendiente'])->assertUnprocessable();
    }

    public function test_http_assignments_validate_resource_type_eligibility_and_actor_identity(): void
    {
        $trip = $this->trip($this->request());
        $resources = $this->assignmentResources();
        $this->actingAs($this->actor('secretary'));
        foreach ($resources as $kind => $resource) {
            $this->postJson($this->url($trip, '/assignments/assign'), ['resource_kind' => $kind, 'resource_id' => $resource->id, 'reason' => 'Asignar'])->assertCreated();
        }
        $other = $this->trip($this->request());
        $this->postJson($this->url($other, '/assignments/assign'), ['resource_kind' => 'tanker', 'resource_id' => $resources['tractor_unit']->id, 'reason' => 'Tipo erróneo'])->assertUnprocessable();
        $resources['driver']->update(['is_active' => false]);
        $this->getJson('/api/transport/availability?resource_kind=driver&resource_id='.$resources['driver']->id)->assertOk()->assertJsonPath('data.eligible', false);
        $this->postJson($this->url($other, '/assignments/assign'), ['resource_kind' => 'driver', 'resource_id' => $resources['driver']->id, 'reason' => 'Inactivo'])->assertUnprocessable();
        $this->postJson($this->url($trip, '/assignments/release'), ['resource_kind' => 'driver', 'reason' => 'Falso actor', 'actor_id' => $trip->registered_by_id])->assertUnprocessable();
        $this->assertSame(1, $trip->driverAssignments()->current()->count());
    }

    public function test_transport_writes_keep_csrf_protection_and_logout_invalidates_access(): void
    {
        $user = $this->actor('secretary');
        $user->forceFill(['login' => 'secretaria_sintetica'])->save();
        $this->postJson('/api/login', ['login' => $user->login, 'password' => 'password'])->assertOk();
        $this->getJson('/api/transport/requests')->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->getJson('/api/transport/requests')->assertUnauthorized();
        $this->actingAs($user);
        $this->app['env'] = 'local';
        $this->postJson('/api/transport/trips', [])->assertStatus(419);
        $this->patchJson('/api/transport/requests/'.Str::uuid(), [])->assertStatus(419);
    }

    public function test_http_rejects_foreign_load_details_without_partial_unload_or_private_sql_exposure(): void
    {
        $first = $this->ready(true);
        $second = $this->ready(true);
        $this->operationalRecords($first);
        $this->actingAs($second->registeredBy);
        $item = $first->tripLoad()->first()->items()->first();
        $response = $this->postJson($this->url($second, '/unload'), ['operation_site_id' => $second->transportRequest->route->destination_site_id,
            'occurred_at' => '2026-10-12T00:00:00Z', 'reason' => 'Carga ajena', 'items' => [['load_item_id' => $item->id, 'liters' => '100']]])->assertNotFound();
        // No load of the second trip: not-found is intentional before item validation.
        $assignment = $second->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        app(TripOperations::class)->load($second->registeredBy, $second->id, $second->transportRequest->route->origin_site_id,
            [$assignment->fleetAsset->tanker->compartments->first()->id => '100'], CarbonImmutable::now(), 'Carga propia');
        $response = $this->postJson($this->url($second, '/unload'), ['operation_site_id' => $second->transportRequest->route->destination_site_id,
            'occurred_at' => '2026-10-12T00:00:00Z', 'reason' => 'Carga ajena', 'items' => [['load_item_id' => $item->id, 'liters' => '100']]])->assertUnprocessable();
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSame(0, $second->unload()->count());
    }

    public function test_http_operational_inputs_reject_bad_dates_precision_capacity_and_duplicate_details_atomically(): void
    {
        $trip = $this->ready();
        $this->actingAs($trip->registeredBy);
        $compartment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first()->fleetAsset->tanker->compartments->first();
        $base = ['operation_site_id' => $trip->transportRequest->route->origin_site_id, 'occurred_at' => '2026-10-12T08:00:00-06:00', 'reason' => 'Captura',
            'items' => [['compartment_id' => $compartment->id, 'liters' => '100']]];
        foreach (['-1', '1.234', '100000000000000', '1000.01'] as $amount) {
            $data = $base;
            $data['items'][0]['liters'] = $amount;
            $this->postJson($this->url($trip, '/load'), $data)->assertUnprocessable();
            $this->assertSame(0, $trip->tripLoad()->count());
        }
        $this->postJson($this->url($trip, '/load'), array_replace($base, ['occurred_at' => 'tomorrow']))->assertUnprocessable();
        $this->postJson($this->url($trip, '/load'), array_replace($base, ['items' => [$base['items'][0], $base['items'][0]]]))->assertUnprocessable();
        $this->postJson($this->url($trip, '/schedule'), ['version' => 1, 'reason' => 'Horario inválido',
            'scheduled_start_at' => '2026-10-13T12:00:00Z', 'scheduled_end_at' => '2026-10-13T11:00:00Z'])->assertUnprocessable();
        $this->postJson('/api/transport/trips', ['transport_request_id' => $trip->transport_request_id, 'execution_status' => 'completed'])->assertUnprocessable();
    }

    public function test_historic_evidence_private_references_are_not_serialized_or_modified(): void
    {
        $trip = $this->ready();
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
        $evidence = ['source' => 'Conductor', 'attachment' => ['storage_key' => 'synthetic/private/file', 'file_path' => '/synthetic/private/file']];
        $load = app(TripOperations::class)->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            [$assignment->fleetAsset->tanker->compartments->first()->id => '100'], CarbonImmutable::now(), 'Evidencia histórica', $evidence);
        $original = $load->fresh()->evidence;
        $this->assertEquals($evidence, $original);
        $this->actingAs($this->actor('secretary'))->getJson($this->url($trip, '/load'))->assertOk()
            ->assertJsonPath('data.evidence.source', 'Conductor')->assertJsonMissingPath('data.evidence.attachment.storage_key')->assertJsonMissingPath('data.evidence.attachment.file_path');
        $this->assertSame($original, $load->fresh()->evidence);
    }

    public function test_lookups_are_read_only_and_do_not_expose_personal_or_document_storage_data(): void
    {
        $trip = $this->ready();
        $this->actingAs($this->actor('secretary'));
        foreach (['customers', 'products', 'sites', 'routes', 'drivers', 'assets'] as $kind) {
            $response = $this->getJson('/api/transport/lookups/'.$kind)->assertOk();
            foreach (['password', 'email', 'storage_key', 'storage_path'] as $field) {
                $this->assertStringNotContainsString('"'.$field.'"', $response->getContent());
            }
        }
        $this->postJson('/api/transport/lookups/drivers', [])->assertMethodNotAllowed();
        $this->getJson('/api/transport/lookups/unknown')->assertNotFound();
    }
}
