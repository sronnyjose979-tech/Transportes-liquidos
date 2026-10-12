<?php

namespace App\Modules\TransportAndDispatch\Application;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationDecision;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationRequest;
use App\Modules\TransportAndDispatch\Models\TransportChange;
use App\Modules\TransportAndDispatch\Models\TransportRequest;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripWarning;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TransportAdministration
{
    public function transaction(User $actor, callable $write): mixed
    {
        return DB::transaction(function () use ($actor, $write) {
            DB::select('SELECT pg_advisory_xact_lock(873210)');
            Gate::forUser($actor)->authorize('manage-transport-core');
            DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
            $result = $write();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $result;
        });
    }

    public function createRequest(User $actor, array $data): TransportRequest
    {
        $data['requested_at'] = CarbonImmutable::parse($data['requested_at'])->utc();

        return $this->transaction($actor, fn () => TransportRequest::create($data + ['registered_by_id' => $actor->id])->refresh());
    }

    public function updateRequest(User $actor, string $id, array $data): TransportRequest
    {
        return $this->transaction($actor, function () use ($actor, $id, $data) {
            $request = TransportRequest::query()->lockForUpdate()->findOrFail($id);
            $this->version($request, $data['version']);
            if (isset($data['requested_at'])) {
                $data['requested_at'] = CarbonImmutable::parse($data['requested_at'])->utc();
            }
            $this->change($request, array_intersect_key($data, array_flip(['requested_liters', 'requested_at'])), $actor, $data['reason'], 'request_update');

            return $request->refresh();
        });
    }

    public function createTrip(User $actor, array $data): Trip
    {
        return $this->transaction($actor, function () use ($actor, $data) {
            $request = TransportRequest::query()->with('liquidProduct')->lockForUpdate()->findOrFail($data['transport_request_id']);

            return Trip::create(['transport_request_id' => $request->id, 'liquid_product_id' => $request->liquid_product_id,
                'product_name_snapshot' => $request->liquidProduct->name, 'registered_by_id' => $actor->id])->refresh();
        });
    }

    public function tripAction(User $actor, string $id, string $action, array $data): Trip
    {
        return $this->transaction($actor, function () use ($actor, $id, $action, $data) {
            $trip = Trip::query()->lockForUpdate()->findOrFail($id);
            $this->version($trip, $data['version']);
            if ($action === 'start') {
                return app(TripOperations::class)->start($actor, $id, $data['reason']);
            }
            if ($action === 'finish') {
                return app(TripOperations::class)->finish($actor, $id, $data['reason'], $data['decision_id'] ?? null);
            }
            $now = DB::selectOne('SELECT clock_timestamp() AS instant')->instant;
            if (in_array($action, ['schedule', 'reschedule'], true)) {
                if ($trip->execution_status !== 'pending' || $trip->officially_started_at !== null) {
                    throw ValidationException::withMessages(['trip' => 'Solo se programa un viaje no iniciado.']);
                }
                app(AssignmentAvailability::class)->revalidate($trip);
                $changes = ['scheduled_start_at' => CarbonImmutable::parse($data['scheduled_start_at'])->utc(), 'scheduled_end_at' => isset($data['scheduled_end_at']) ? CarbonImmutable::parse($data['scheduled_end_at'])->utc() : null,
                    'planning_status' => $action === 'reschedule' ? 'rescheduled' : 'scheduled'];
            } elseif ($action === 'cancel') {
                if (! in_array($trip->execution_status, ['pending', 'in_progress'], true)) {
                    throw ValidationException::withMessages(['trip' => 'Cancelación solo definida para viaje pendiente o en curso.']);
                }
                $changes = ['execution_status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by_id' => $actor->id, 'cancellation_reason' => $data['reason']];
            } else {
                if ($trip->administrative_status !== 'open' || ! in_array($trip->execution_status, ['completed', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['trip' => 'Cierre requiere viaje finalizado o cancelado y abierto.']);
                }
                $changes = ['administrative_status' => 'closed', 'closed_at' => $now, 'closed_by_id' => $actor->id];
            }
            $this->change($trip, $changes, $actor, $data['reason'], $action);
            if ($action === 'close') {
                foreach ($data['warnings'] ?? [] as $warning) {
                    TripWarning::create($warning + ['trip_id' => $trip->id, 'closure_version' => $trip->version]);
                }
            }

            return $trip->refresh();
        });
    }

    public function requestAuthorization(User $actor, string $tripId, array $data): TransportAuthorizationRequest
    {
        return $this->transaction($actor, function () use ($actor, $tripId, $data) {
            $trip = Trip::query()->lockForUpdate()->findOrFail($tripId);
            if ($data['type'] !== 'volume_difference') {
                $input = $data['content'];
                $kind = $input['resource_kind'];
                $action = $input['action'];
                $expected = $kind === 'driver' ? 'driver_substitution' : 'tractor_substitution';
                if (($action === 'substitute' && $kind === 'tanker') || ($data['type'] !== 'assignment_conflict' && ($action !== 'substitute' || $data['type'] !== $expected))) {
                    throw ValidationException::withMessages(['type' => 'Autorización incompatible o sustitución de cisterna pendiente D05.']);
                }
                $info = app(AssignmentAvailability::class)->inspect($kind, $input['resource_id']);
                if (! $info['eligible']) {
                    throw ValidationException::withMessages(['resource_id' => 'Recurso no elegible.']);
                }
                $previous = $kind === 'driver' ? $trip->driverAssignments()->current()->first() : $trip->assetAssignments()->current()->where('asset_type', $kind)->first();
                $payload = ['action' => $action, 'resource_kind' => $kind, 'resource_id' => $input['resource_id'],
                    'previous_assignment_id' => $previous?->id, 'conflicts' => $info['conflicts']->pluck('id')->all()];

                return app(AssignmentAdministration::class)->requestAuthorization($actor, $tripId, $data['type'], $payload, $data['reason'])->refresh();
            }
            DB::select('SELECT trip_require_completion(?)', [$trip->id]);
            $content = DB::selectOne('SELECT trip_volume_content(?)::text AS content', [$trip->id])->content;
            if ($content === null) {
                throw ValidationException::withMessages(['type' => 'No existe diferencia de litros registrada.']);
            }

            return TransportAuthorizationRequest::create(['trip_id' => $trip->id, 'type' => 'volume_difference',
                'target_version' => $trip->version, 'content' => json_decode($content, true), 'requested_by_id' => $actor->id, 'reason' => $data['reason']])->refresh();
        });
    }

    public function decide(User $actor, string $id, array $data): TransportAuthorizationDecision
    {
        return $this->transaction($actor, function () use ($actor, $id, $data) {
            Gate::forUser($actor)->authorize('decide-transport-authorization');
            $intent = TransportAuthorizationRequest::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($intent->type, ['assignment_conflict', 'driver_substitution', 'tractor_substitution', 'volume_difference'], true)) {
                throw ValidationException::withMessages(['type' => 'Autorización fuera del alcance aprobado.']);
            }
            if ($intent->decision()->exists()) {
                throw new ConflictHttpException('La solicitud ya tiene decisión.');
            }

            return TransportAuthorizationDecision::create(['authorization_request_id' => $id, 'decision' => $data['decision'], 'decided_by_id' => $actor->id, 'reason' => $data['reason']])->refresh();
        });
    }

    private function version(Model $target, int $version): void
    {
        if ($target->version !== $version) {
            throw new ConflictHttpException('La versión cambió; consulte el recurso antes de confirmar.');
        }
    }

    private function change(Model $target, array $data, User $actor, string $reason, string $action): void
    {
        $before = $this->snapshot($target);
        $target->forceFill($data + ['version' => $target->version + 1])->save();
        TransportChange::create(['transport_request_id' => $target instanceof TransportRequest ? $target->id : null,
            'trip_id' => $target instanceof Trip ? $target->id : null, 'action' => $action, 'before_values' => $before,
            'after_values' => $this->snapshot($target), 'from_version' => $before['version'], 'to_version' => $target->version, 'actor_id' => $actor->id, 'reason' => $reason]);
    }

    private function snapshot(Model $target): array
    {
        return json_decode(DB::selectOne('SELECT to_jsonb(t)::text AS data FROM '.$target->getTable().' t WHERE id=?', [$target->id])->data, true);
    }
}
