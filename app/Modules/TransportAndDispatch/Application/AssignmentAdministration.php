<?php

namespace App\Modules\TransportAndDispatch\Application;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\DriverAssignment;
use App\Modules\TransportAndDispatch\Models\DriverRelease;
use App\Modules\TransportAndDispatch\Models\TransportAssignmentConflict;
use App\Modules\TransportAndDispatch\Models\TransportAssignmentOperation;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationApplication;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationDecision;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationRequest;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripAssetAssignment;
use App\Modules\TransportAndDispatch\Models\TripAssetRelease;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignmentAdministration
{
    public function assign(User $actor, string $tripId, string $kind, string $resourceId, string $reason, array $decisions = []): Model
    {
        return $this->write($actor, $tripId, $kind, $resourceId, $reason, 'assign', $decisions);
    }

    public function substitute(User $actor, string $tripId, string $kind, string $resourceId, string $reason, array $decisions = []): Model
    {
        return $this->write($actor, $tripId, $kind, $resourceId, $reason, 'substitute', $decisions);
    }

    public function release(User $actor, string $tripId, string $kind, string $reason): Model
    {
        return $this->write($actor, $tripId, $kind, null, $reason, 'release', []);
    }

    public function requestAuthorization(User $actor, string $tripId, string $type, array $content, string $reason): TransportAuthorizationRequest
    {
        if (! in_array($type, ['assignment_conflict', 'driver_substitution', 'tractor_substitution', 'tanker_substitution'], true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de autorización de asignación inválido.']);
        }

        return DB::transaction(function () use ($actor, $tripId, $type, $content, $reason): TransportAuthorizationRequest {
            $this->authorize($actor, 'manage-trip-assignments');
            $trip = Trip::query()->lockForUpdate()->findOrFail($tripId);

            return TransportAuthorizationRequest::create(['trip_id' => $trip->id, 'type' => $type, 'content' => $content,
                'target_version' => $trip->version, 'requested_by_id' => $actor->id, 'reason' => $reason]);
        });
    }

    public function decide(User $actor, string $intentId, bool $approved, string $reason): TransportAuthorizationDecision
    {
        return DB::transaction(function () use ($actor, $intentId, $approved, $reason): TransportAuthorizationDecision {
            $this->authorize($actor, 'authorize-trip-assignment-exception');
            $intent = TransportAuthorizationRequest::query()->findOrFail($intentId);
            if (! in_array($intent->type, ['assignment_conflict', 'driver_substitution', 'tractor_substitution', 'tanker_substitution'], true)) {
                throw ValidationException::withMessages(['type' => 'La autorización no pertenece a asignaciones.']);
            }

            return TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => $approved ? 'approved' : 'rejected',
                'decided_by_id' => $actor->id, 'reason' => $reason]);
        });
    }

    private function authorize(User $actor, string $ability): void
    {
        DB::select('SELECT pg_advisory_xact_lock(873210)');
        Gate::forUser($actor)->authorize($ability);
    }

    private function write(User $actor, string $tripId, string $kind, ?string $resourceId, string $reason, string $action, array $decisions): Model
    {
        if (! in_array($kind, ['driver', 'tractor_unit', 'tanker'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['assignment' => 'Recurso y motivo son obligatorios.']);
        }
        try {
            $result = DB::transaction(function () use ($actor, $tripId, $kind, $resourceId, $reason, $action, $decisions): array {
                $this->authorize($actor, 'manage-trip-assignments');
                // Guard writes force stale REPEATABLE READ snapshots to fail, even after lock waits.
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                $trip = Trip::query()->lockForUpdate()->findOrFail($tripId);
                $class = $kind === 'driver' ? DriverAssignment::class : TripAssetAssignment::class;
                $query = $class::current()->where('trip_id', $tripId);
                if ($kind !== 'driver') {
                    $query->where('asset_type', $kind);
                }
                $previous = $query->first();
                if (($action === 'assign' && $previous !== null) || ($action !== 'assign' && $previous === null)) {
                    throw ValidationException::withMessages(['assignment' => 'La operación no coincide con la asignación vigente.']);
                }
                if ($action === 'substitute' && $kind === 'tanker') {
                    throw ValidationException::withMessages(['assignment' => 'La sustitución de cisterna requiere definir cómo comprobar que está vacía.']);
                }
                if ($action !== 'release' && ! app(AssignmentAvailability::class)->inspect($kind, $resourceId)['eligible']) {
                    throw ValidationException::withMessages(['resource_id' => 'El conductor o activo no es elegible para esta función.']);
                }
                $column = $kind === 'driver' ? 'employee_id' : 'fleet_asset_id';
                if ($action === 'substitute' && $previous->{$column} === $resourceId) {
                    throw ValidationException::withMessages(['assignment' => 'El recurso sustituto debe ser distinto.']);
                }
                $conflicts = $action === 'release' ? [] : $class::current()->where($column, $resourceId)->orderBy('id')->pluck('id')->all();
                $payload = ['action' => $action, 'resource_kind' => $kind, 'resource_id' => $resourceId,
                    'previous_assignment_id' => $previous?->id, 'conflicts' => $conflicts];
                $valid = TransportAuthorizationDecision::query()->with('authorizationRequest')->whereIn('id', $decisions)
                    ->where('decision', 'approved')->whereDoesntHave('application')->get()->filter(function ($decision) use ($trip, $payload): bool {
                        $intent = $decision->authorizationRequest;

                        // JSON object key order is irrelevant; array order for conflicts is canonical.
                        return $intent->trip_id === $trip->id && $intent->target_version === $trip->version && $intent->content == $payload;
                    });
                if ($conflicts !== [] && ! $valid->contains(fn ($d) => $d->authorizationRequest->type === 'assignment_conflict')) {
                    $operation = $this->operation($trip, $actor, 'conflict_detected', $kind, $payload, $reason);
                    $this->recordConflicts($operation, $kind, $conflicts);
                    $this->checkConstraints();

                    return ['error' => true, 'operation' => $operation];
                }
                $expected = $kind === 'driver' ? 'driver_substitution' : 'tractor_substitution';
                if ($action === 'substitute' && $trip->execution_status === 'in_progress' && ! $valid->contains(fn ($d) => $d->authorizationRequest->type === $expected)) {
                    throw ValidationException::withMessages(['authorization' => 'La sustitución requiere autorización administrativa específica.']);
                }
                $operation = $this->operation($trip, $actor, $action, $kind, $payload, $reason);
                $this->recordConflicts($operation, $kind, $conflicts);
                $record = null;
                if ($action !== 'assign') {
                    $releaseClass = $kind === 'driver' ? DriverRelease::class : TripAssetRelease::class;
                    $record = $releaseClass::create(['assignment_id' => $previous->id, 'operation_id' => $operation->id]);
                }
                if ($action !== 'release') {
                    $fields = ['trip_id' => $trip->id, $column => $resourceId, 'operation_id' => $operation->id];
                    if ($kind !== 'driver') {
                        $fields['asset_type'] = $kind;
                    }
                    $record = $class::create($fields);
                }
                foreach ($valid as $decision) {
                    TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'operation_id' => $operation->id, 'applied_by_id' => $actor->id]);
                }
                $this->checkConstraints();

                return ['error' => false, 'record' => $record->refresh()];
            });
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['23514', '23505', '23503'], true)) {
                throw ValidationException::withMessages(['assignment' => 'La operación incumple la integridad o su autorización.']);
            }
            throw $exception;
        }
        if ($result['error']) {
            // Persist the detected conflict before reporting the rejected mutation.
            throw ValidationException::withMessages(['assignment' => 'Recurso ocupado. Conflicto registrado: '.$result['operation']->id])->status(409);
        }

        return $result['record'];
    }

    private function operation(Trip $trip, User $actor, string $action, string $kind, array $payload, string $reason): TransportAssignmentOperation
    {
        return TransportAssignmentOperation::create(['trip_id' => $trip->id, 'target_version' => $trip->version, 'action' => $action,
            'resource_kind' => $kind, 'payload' => $payload, 'actor_id' => $actor->id, 'reason' => $reason]);
    }

    private function recordConflicts(TransportAssignmentOperation $operation, string $kind, array $ids): void
    {
        foreach ($ids as $id) {
            TransportAssignmentConflict::create(['operation_id' => $operation->id, $kind === 'driver' ? 'driver_assignment_id' : 'asset_assignment_id' => $id]);
        }
    }

    private function checkConstraints(): void
    {
        DB::statement('SET CONSTRAINTS assignment_operation_integrity, assignment_trip_integrity IMMEDIATE');
        DB::statement('SET CONSTRAINTS assignment_operation_integrity, assignment_trip_integrity DEFERRED');
    }
}
