<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use App\Modules\TransportAndDispatch\Models\DriverAssignment;
use App\Modules\TransportAndDispatch\Models\DriverRelease;
use App\Modules\TransportAndDispatch\Models\TransportAssignmentOperation;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationApplication;
use App\Modules\TransportAndDispatch\Models\TransportChange;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripAssetAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TripAssignmentTest extends PostgresTestCase
{
    use TransportFixtures;

    private function service(): AssignmentAdministration
    {
        return app(AssignmentAdministration::class);
    }

    private function invalid(callable $write): void
    {
        try {
            $write();
            $this->fail('Debía rechazarse la operación.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function activeTrip(): Trip
    {
        $trip = $this->trip($this->request());
        $this->change($trip, ['execution_status' => 'in_progress', 'officially_started_at' => '2026-10-11T12:00:00Z',
            'started_by_id' => $trip->registered_by_id]);
        $this->checkConstraints();

        return $trip->refresh();
    }

    private function payload(Trip $trip, string $kind, string $resourceId, string $action = 'assign'): array
    {
        $class = $kind === 'driver' ? DriverAssignment::class : TripAssetAssignment::class;
        $previous = $class::current()->where('trip_id', $trip->id);
        if ($kind !== 'driver') {
            $previous->where('asset_type', $kind);
        }
        $column = $kind === 'driver' ? 'employee_id' : 'fleet_asset_id';

        return ['action' => $action, 'resource_kind' => $kind, 'resource_id' => $resourceId,
            'previous_assignment_id' => $previous->first()?->id,
            'conflicts' => $class::current()->where($column, $resourceId)->orderBy('id')->pluck('id')->all()];
    }

    private function approve(Trip $trip, string $type, array $payload): string
    {
        $service = $this->service();
        $intent = $service->requestAuthorization($this->actor('secretary'), $trip->id, $type, $payload, 'Solicitud documentada');

        return $service->decide($this->actor(), $intent->id, true, 'Autorización documentada')->id;
    }

    public function test_valid_assignment_relations_and_manual_individual_release(): void
    {
        $actor = $this->actor('secretary');
        $trip = $this->trip($this->request($actor));
        foreach ($this->assignmentResources() as $kind => $resource) {
            $assignment = $this->service()->assign($actor, $trip->id, $kind, $resource->id, 'Asignación manual');
            $snapshot = $this->row($assignment);
            $this->assertTrue($assignment->trip->is($trip));
            $this->assertSame($actor->id, $assignment->operation->actor_id);
            $this->assertNotNull($assignment->starts_at);
            $release = $this->service()->release($actor, $trip->id, $kind, 'Liberación manual');
            $this->assertTrue($release->assignment->is($assignment));
            $this->assertGreaterThanOrEqual($assignment->starts_at, $release->ends_at);
            $this->assertSame('Liberación manual', $release->operation->reason);
            $this->assertSame($snapshot, $this->row($assignment));
            $this->assertSame(0, $assignment->newQuery()->current()->where('trip_id', $trip->id)->count());
        }
        $this->assertSame(6, $trip->assignmentOperations()->count());
    }

    public function test_driver_requires_active_employee_active_account_and_driver_role(): void
    {
        $trip = $this->trip($this->request());
        foreach ([['is_active' => false, 'user_id' => $this->actor('driver')->id],
            ['is_active' => true, 'user_id' => null],
            ['is_active' => true, 'user_id' => $this->actor('driver', false)->id],
            ['is_active' => true, 'user_id' => $this->actor('mechanic')->id]] as $fields) {
            $employee = Employee::create(['name' => 'No elegible'] + $fields);
            $this->invalid(fn () => $this->service()->assign($trip->registeredBy, $trip->id, 'driver', $employee->id, 'Intento'));
        }
        $this->assertSame(0, $trip->driverAssignments()->count());
    }

    public function test_resource_type_and_service_condition_are_checked(): void
    {
        $trip = $this->trip($this->request());
        $resources = $this->assignmentResources();
        $this->invalid(fn () => $this->service()->assign($trip->registeredBy, $trip->id, 'tractor_unit', $resources['tanker']->id, 'Tipo incorrecto'));
        foreach (['maintenance', 'out_of_service'] as $status) {
            DB::table('fleet_assets')->where('id', $resources['tractor_unit']->id)->update(['service_status' => $status]);
            $this->invalid(fn () => $this->service()->assign($trip->registeredBy, $trip->id, 'tractor_unit', $resources['tractor_unit']->id, 'No disponible'));
        }
        $this->assertSame(0, $trip->assetAssignments()->count());
    }

    public function test_conflicts_of_each_resource_are_logged_and_require_exact_administrator_authorization(): void
    {
        foreach ($this->assignmentResources() as $kind => $resource) {
            $first = $this->trip($this->request());
            $second = $this->trip($this->request());
            $assignment = $this->service()->assign($first->registeredBy, $first->id, $kind, $resource->id, 'Primera');
            $this->invalid(fn () => $this->service()->assign($second->registeredBy, $second->id, $kind, $resource->id, 'Conflicto'));
            $attempt = $second->assignmentOperations()->where('action', 'conflict_detected')->firstOrFail();
            $this->assertSame([$assignment->id], $attempt->payload['conflicts']);
            $this->assertSame(1, $attempt->conflicts()->count());
            $decision = $this->approve($second, 'assignment_conflict', $attempt->payload);
            $new = $this->service()->assign($second->registeredBy, $second->id, $kind, $resource->id, 'Excepción autorizada', [$decision]);
            $this->assertSame(1, $new->operation->applications()->count());
            $this->assertSame(1, $new->operation->conflicts()->count());
            $this->assertNull($assignment->fresh()->release);
        }
    }

    public function test_approval_for_wrong_resource_or_changed_conflict_set_is_not_reused(): void
    {
        $resources = $this->assignmentResources();
        $owner = $this->trip($this->request());
        $target = $this->trip($this->request());
        $other = $this->trip($this->request());
        $employee = $resources['driver'];
        $this->service()->assign($owner->registeredBy, $owner->id, 'driver', $employee->id, 'Primera');
        $payload = $this->payload($target, 'driver', $employee->id);
        $decision = $this->approve($target, 'assignment_conflict', $payload);
        $this->invalid(fn () => $this->service()->assign($other->registeredBy, $other->id, 'driver', $employee->id, 'Otro viaje', [$decision]));
        $this->service()->assign($target->registeredBy, $target->id, 'driver', $employee->id, 'Excepción', [$decision]);
        $this->service()->release($target->registeredBy, $target->id, 'driver', 'Liberación');
        $this->invalid(fn () => $this->service()->assign($target->registeredBy, $target->id, 'driver', $employee->id, 'Reutilización', [$decision]));
        $this->assertSame(1, TransportAuthorizationApplication::where('decision_id', $decision)->count());
    }

    public function test_active_trip_cannot_lose_a_resource_and_substitution_is_atomic_and_authorized(): void
    {
        foreach (['driver', 'tractor_unit'] as $kind) {
            $trip = $this->activeTrip();
            $class = $kind === 'driver' ? DriverAssignment::class : TripAssetAssignment::class;
            $query = $class::current()->where('trip_id', $trip->id);
            if ($kind !== 'driver') {
                $query->where('asset_type', $kind);
            }
            $old = $query->firstOrFail();
            $oldSnapshot = $this->row($old);
            $this->invalid(fn () => $this->service()->release($trip->registeredBy, $trip->id, $kind, 'No dejar incompleto'));
            $newResource = $this->assignmentResources()[$kind];
            $this->invalid(fn () => $this->service()->substitute($trip->registeredBy, $trip->id, $kind, $newResource->id, 'Sin autorización'));
            $type = $kind === 'driver' ? 'driver_substitution' : 'tractor_substitution';
            $decision = $this->approve($trip, $type, $this->payload($trip, $kind, $newResource->id, 'substitute'));
            $new = $this->service()->substitute($this->actor('secretary'), $trip->id, $kind, $newResource->id, 'Sustitución autorizada', [$decision]);
            $this->assertSame($oldSnapshot, $this->row($old));
            $this->assertNotNull($old->fresh()->release);
            $this->assertSame($old->release->operation_id, $new->operation_id);
            $this->assertSame(1, $new->operation->applications()->count());
            $this->assertSame('in_progress', $trip->fresh()->execution_status);
            $this->assertSame(1, $query->count());
        }
    }

    public function test_tanker_substitution_is_left_out_until_empty_verification_is_defined(): void
    {
        $trip = $this->activeTrip();
        $old = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->firstOrFail();
        $replacement = $this->assignmentResources()['tanker'];
        $this->invalid(fn () => $this->service()->substitute($trip->registeredBy, $trip->id, 'tanker', $replacement->id, 'Comprobación pendiente'));
        $this->assertNull($old->fresh()->release);
    }

    public function test_completed_cancelled_and_closed_states_do_not_release_resources(): void
    {
        foreach (['completed', 'cancelled'] as $terminal) {
            $trip = $this->activeTrip();
            if ($terminal === 'completed') {
                $fields = ['execution_status' => 'completed', 'officially_completed_at' => '2026-10-12T12:00:00Z', 'completed_by_id' => $trip->registered_by_id];
            } else {
                $fields = ['execution_status' => 'cancelled', 'cancelled_at' => '2026-10-12T12:00:00Z', 'cancelled_by_id' => $trip->registered_by_id, 'cancellation_reason' => 'Cancelado'];
            }
            $this->change($trip, $fields);
            $this->change($trip, ['administrative_status' => 'closed', 'closed_at' => '2026-10-13T12:00:00Z', 'closed_by_id' => $trip->registered_by_id]);
            $this->checkConstraints();
            $this->assertSame(1, $trip->driverAssignments()->current()->count());
            $this->assertSame(2, $trip->assetAssignments()->current()->count());
            $this->service()->release($this->actor('secretary'), $trip->id, 'driver', 'Manual después de cierre');
            $this->assertSame(0, $trip->driverAssignments()->current()->count());
            $this->assertSame(2, $trip->assetAssignments()->current()->count());
        }
    }

    public function test_assignment_history_is_immutable_and_same_trip_cardinality_cannot_be_bypassed(): void
    {
        $trip = $this->trip($this->request());
        $resources = $this->assignmentResources();
        $assignment = $this->service()->assign($trip->registeredBy, $trip->id, 'driver', $resources['driver']->id, 'Inicial');
        foreach ([$assignment, $assignment->operation] as $record) {
            $this->rejected(fn () => DB::table($record->getTable())->where('id', $record->id)->delete());
        }
        $other = $this->assignmentResources()['driver'];
        $this->invalid(fn () => $this->service()->assign($trip->registeredBy, $trip->id, 'driver', $other->id, 'Segundo responsable'));
        $this->assertSame(1, $trip->driverAssignments()->current()->count());
    }

    public function test_roles_and_revocation_are_enforced_without_expanding_other_permissions(): void
    {
        foreach (['administrator', 'secretary', 'driver', 'mechanic'] as $role) {
            $actor = $this->actor($role);
            $this->assertSame(in_array($role, ['administrator', 'secretary']), Gate::forUser($actor)->allows('manage-trip-assignments'));
            $this->assertSame($role === 'administrator', Gate::forUser($actor)->allows('authorize-trip-assignment-exception'));
        }
        $actor = $this->actor('secretary');
        $trip = $this->trip($this->request());
        $actor->roles()->detach();
        $this->assertFalse(Gate::forUser($actor)->allows('manage-trip-assignments'));
        $this->expectException(AuthorizationException::class);
        $this->service()->assign($actor, $trip->id, 'driver', $this->assignmentResources()['driver']->id, 'Acceso revocado');
    }

    public function test_secretary_cannot_decide_exception_and_driver_revocation_blocks_new_assignment(): void
    {
        $trip = $this->trip($this->request());
        $driver = $this->assignmentResources()['driver'];
        $payload = $this->payload($trip, 'driver', $driver->id);
        $intent = $this->service()->requestAuthorization($this->actor('secretary'), $trip->id, 'assignment_conflict', $payload, 'Solicitud');
        try {
            $this->service()->decide($this->actor('secretary'), $intent->id, true, 'No autorizado');
            $this->fail('Secretaria no puede aprobar.');
        } catch (AuthorizationException $exception) {
            $this->assertNull($intent->fresh()->decision);
        }
        $driver->user->roles()->detach();
        $this->invalid(fn () => $this->service()->assign($trip->registeredBy, $trip->id, 'driver', $driver->id, 'Rol revocado'));
        $this->assertSame(0, $trip->driverAssignments()->count());
    }

    public function test_new_conflict_invalidates_previous_approved_set(): void
    {
        $driver = $this->assignmentResources()['driver'];
        $owner = $this->trip($this->request());
        $target = $this->trip($this->request());
        $third = $this->trip($this->request());
        $this->service()->assign($owner->registeredBy, $owner->id, 'driver', $driver->id, 'Primera');
        $oldApproval = $this->approve($target, 'assignment_conflict', $this->payload($target, 'driver', $driver->id));
        $thirdApproval = $this->approve($third, 'assignment_conflict', $this->payload($third, 'driver', $driver->id));
        $this->service()->assign($third->registeredBy, $third->id, 'driver', $driver->id, 'Otra excepción', [$thirdApproval]);
        $this->invalid(fn () => $this->service()->assign($target->registeredBy, $target->id, 'driver', $driver->id, 'Aprobación obsoleta', [$oldApproval]));
        $attempt = $target->assignmentOperations()->where('action', 'conflict_detected')->firstOrFail();
        $this->assertCount(2, $attempt->payload['conflicts']);
        $this->assertSame(0, $target->driverAssignments()->count());
        $this->assertSame(0, TransportAuthorizationApplication::where('decision_id', $oldApproval)->count());
    }

    public function test_substitution_with_occupied_resource_requires_both_approvals(): void
    {
        $target = $this->activeTrip();
        $owner = $this->trip($this->request());
        $driver = $this->assignmentResources()['driver'];
        $this->service()->assign($owner->registeredBy, $owner->id, 'driver', $driver->id, 'Recurso ocupado');
        $payload = $this->payload($target, 'driver', $driver->id, 'substitute');
        $replacementApproval = $this->approve($target, 'driver_substitution', $payload);
        $this->invalid(fn () => $this->service()->substitute($target->registeredBy, $target->id, 'driver', $driver->id, 'Falta excepción', [$replacementApproval]));
        $conflictApproval = $this->approve($target, 'assignment_conflict', $payload);
        $new = $this->service()->substitute($target->registeredBy, $target->id, 'driver', $driver->id, 'Ambas aprobaciones', [$replacementApproval, $conflictApproval]);
        $this->assertSame(2, $new->operation->applications()->count());
        $this->assertSame(1, $new->operation->conflicts()->count());
        $this->assertSame(1, $target->driverAssignments()->current()->count());
    }

    public function test_active_trip_and_direct_sql_operation_cannot_bypass_integrity(): void
    {
        $trip = $this->trip($this->request());
        $this->rejected(function () use ($trip): void {
            $before = $this->row($trip);
            $trip->forceFill(['execution_status' => 'in_progress', 'officially_started_at' => '2026-10-11T12:00:00Z',
                'started_by_id' => $trip->registered_by_id, 'version' => 2])->save();
            TransportChange::create(['trip_id' => $trip->id, 'action' => 'start',
                'before_values' => $before, 'after_values' => $this->row($trip), 'from_version' => 1, 'to_version' => 2,
                'actor_id' => $trip->registered_by_id, 'reason' => 'Sin recursos']);
        });
        $this->assertSame('pending', $trip->fresh()->execution_status);
        $this->rejected(fn () => TransportAssignmentOperation::create(['trip_id' => $trip->id, 'action' => 'assign', 'resource_kind' => 'driver',
            'target_version' => 1, 'actor_id' => $trip->registered_by_id, 'reason' => 'Operación incompleta',
            'payload' => ['action' => 'assign', 'resource_kind' => 'driver', 'resource_id' => (string) Str::uuid(), 'previous_assignment_id' => null, 'conflicts' => []]]));
        $this->assertSame(0, $trip->assignmentOperations()->count());
    }

    public function test_direct_sql_cannot_substitute_active_driver_without_approval_or_disguise_it_as_release_and_assign(): void
    {
        $trip = $this->activeTrip();
        $old = $trip->driverAssignments()->current()->firstOrFail();
        $driver = $this->assignmentResources()['driver'];
        $this->rejected(function () use ($trip, $old, $driver): void {
            $operation = TransportAssignmentOperation::create(['trip_id' => $trip->id, 'action' => 'substitute', 'resource_kind' => 'driver',
                'target_version' => $trip->version, 'actor_id' => $trip->registered_by_id, 'reason' => 'Sin aprobación',
                'payload' => $this->payload($trip, 'driver', $driver->id, 'substitute')]);
            DriverRelease::create(['assignment_id' => $old->id, 'operation_id' => $operation->id]);
            DriverAssignment::create(['trip_id' => $trip->id, 'employee_id' => $driver->id, 'operation_id' => $operation->id]);
        });
        $this->rejected(function () use ($trip, $old, $driver): void {
            $release = TransportAssignmentOperation::create(['trip_id' => $trip->id, 'action' => 'release', 'resource_kind' => 'driver',
                'target_version' => $trip->version, 'actor_id' => $trip->registered_by_id, 'reason' => 'Liberación disfrazada',
                'payload' => ['action' => 'release', 'resource_kind' => 'driver', 'resource_id' => null, 'previous_assignment_id' => $old->id, 'conflicts' => []]]);
            DriverRelease::create(['assignment_id' => $old->id, 'operation_id' => $release->id]);
            $assign = TransportAssignmentOperation::create(['trip_id' => $trip->id, 'action' => 'assign', 'resource_kind' => 'driver',
                'target_version' => $trip->version, 'actor_id' => $trip->registered_by_id, 'reason' => 'Asignación disfrazada',
                'payload' => ['action' => 'assign', 'resource_kind' => 'driver', 'resource_id' => $driver->id, 'previous_assignment_id' => null, 'conflicts' => []]]);
            DriverAssignment::create(['trip_id' => $trip->id, 'employee_id' => $driver->id, 'operation_id' => $assign->id]);
        });
        $this->assertNull($old->fresh()->release);
        $this->assertSame(1, $trip->driverAssignments()->count());
    }
}
