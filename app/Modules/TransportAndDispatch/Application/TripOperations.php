<?php

namespace App\Modules\TransportAndDispatch\Application;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\LoadItem;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationApplication;
use App\Modules\TransportAndDispatch\Models\TransportChange;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripEvent;
use App\Modules\TransportAndDispatch\Models\TripLoad;
use App\Modules\TransportAndDispatch\Models\TripOdometerReading;
use App\Modules\TransportAndDispatch\Models\TripUnload;
use App\Modules\TransportAndDispatch\Models\UnloadItem;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TripOperations
{
    public function start(User $actor, string $tripId, string $reason): Trip
    {
        return $this->transaction(function () use ($actor, $tripId, $reason): Trip {
            $trip = $this->authorize($actor, $tripId);
            if ($trip->execution_status !== 'pending' || trim($reason) === '') {
                throw ValidationException::withMessages(['trip' => 'El inicio requiere viaje pendiente y motivo.']);
            }
            $before = $this->snapshot($trip);
            $trip->forceFill(['execution_status' => 'in_progress', 'officially_started_at' => DB::selectOne('SELECT clock_timestamp() AS instant')->instant,
                'started_by_id' => $actor->id, 'version' => $trip->version + 1])->save();
            $after = $this->snapshot($trip);
            TransportChange::create(['trip_id' => $trip->id, 'action' => 'official_start', 'before_values' => $before, 'after_values' => $after,
                'from_version' => $before['version'], 'to_version' => $after['version'], 'actor_id' => $actor->id, 'reason' => $reason]);
            DB::statement('SET CONSTRAINTS transport_audit, assignment_trip_integrity IMMEDIATE');
            DB::statement('SET CONSTRAINTS transport_audit, assignment_trip_integrity DEFERRED');

            return $trip->refresh();
        });
    }

    public function finish(User $actor, string $tripId, string $reason, ?string $decisionId = null): Trip
    {
        return $this->transaction(function () use ($actor, $tripId, $reason, $decisionId): Trip {
            $trip = $this->authorize($actor, $tripId);
            if ($trip->execution_status !== 'in_progress' || trim($reason) === '') {
                throw ValidationException::withMessages(['trip' => 'La finalización requiere viaje en curso y motivo.']);
            }
            DB::select('SELECT trip_require_completion(?)', [$trip->id]);
            $different = DB::selectOne('SELECT trip_volume_content(?) IS NOT NULL AS different', [$trip->id])->different;
            if ($different && $decisionId === null) {
                throw ValidationException::withMessages(['authorization' => 'La diferencia de litros requiere autorización administrativa específica.']);
            }
            if (! $different && $decisionId !== null) {
                throw ValidationException::withMessages(['authorization' => 'No corresponde consumir autorización sin diferencia.']);
            }
            $before = $this->snapshot($trip);
            $trip->forceFill(['execution_status' => 'completed', 'officially_completed_at' => DB::selectOne('SELECT clock_timestamp() AS instant')->instant,
                'completed_by_id' => $actor->id, 'version' => $trip->version + 1])->save();
            $after = $this->snapshot($trip);
            $change = TransportChange::create(['trip_id' => $trip->id, 'action' => 'official_completion', 'before_values' => $before, 'after_values' => $after,
                'from_version' => $before['version'], 'to_version' => $after['version'], 'actor_id' => $actor->id, 'reason' => $reason]);
            if ($different) {
                TransportAuthorizationApplication::create(['decision_id' => $decisionId, 'change_id' => $change->id,
                    'validation_type' => 'volume_difference', 'applied_by_id' => $actor->id]);
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $trip->refresh();
        });
    }

    public function load(User $actor, string $tripId, string $siteId, array $amounts, DateTimeInterface $occurredAt, string $reason, array $evidence = []): TripLoad
    {
        return $this->transaction(function () use ($actor, $tripId, $siteId, $amounts, $occurredAt, $reason, $evidence): TripLoad {
            $trip = $this->authorize($actor, $tripId);
            $this->requireDetails($amounts);
            $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->firstOrFail();
            $data = $this->data($actor, $occurredAt, $reason, $evidence);
            $load = TripLoad::create(['trip_id' => $trip->id, 'tanker_assignment_id' => $assignment->id, 'operation_site_id' => $siteId] + $data);
            foreach ($amounts as $compartmentId => $liters) {
                LoadItem::create(['trip_load_id' => $load->id, 'compartment_id' => $compartmentId, 'loaded_liters' => $liters] + $data);
            }

            return $load->refresh()->load('items');
        });
    }

    public function unload(User $actor, string $tripId, string $siteId, array $amounts, DateTimeInterface $occurredAt, string $reason, array $evidence = []): TripUnload
    {
        return $this->transaction(function () use ($actor, $tripId, $siteId, $amounts, $occurredAt, $reason, $evidence): TripUnload {
            $trip = $this->authorize($actor, $tripId);
            $this->requireDetails($amounts);
            $load = $trip->tripLoad()->firstOrFail();
            $data = $this->data($actor, $occurredAt, $reason, $evidence);
            $unload = TripUnload::create(['trip_id' => $trip->id, 'trip_load_id' => $load->id, 'operation_site_id' => $siteId] + $data);
            foreach ($amounts as $loadItemId => $liters) {
                UnloadItem::create(['trip_unload_id' => $unload->id, 'load_item_id' => $loadItemId, 'delivered_liters' => $liters] + $data);
            }

            return $unload->refresh()->load('items');
        });
    }

    /** Append omitted details; existing operation headers and quantities remain immutable. */
    public function addDetails(User $actor, string $tripId, bool $loading, array $amounts, DateTimeInterface $occurredAt, string $reason, array $evidence = []): TripLoad|TripUnload
    {
        return $this->transaction(function () use ($actor, $tripId, $loading, $amounts, $occurredAt, $reason, $evidence): TripLoad|TripUnload {
            $trip = $this->authorize($actor, $tripId);
            if (! in_array($trip->execution_status, ['pending', 'in_progress'], true)) {
                throw ValidationException::withMessages(['trip' => 'La captura de detalles faltantes no corrige operaciones de viajes terminados.']);
            }
            $this->requireDetails($amounts);
            $record = ($loading ? $trip->tripLoad() : $trip->unload())->firstOrFail();
            if ($loading && ! $trip->assetAssignments()->current()->whereKey($record->tanker_assignment_id)->exists()) {
                throw ValidationException::withMessages(['trip' => 'La cisterna registrada debe continuar asignada para completar la carga.']);
            }
            $data = $this->data($actor, $occurredAt, $reason, $evidence);
            foreach ($amounts as $id => $liters) {
                if ($loading) {
                    LoadItem::create(['trip_load_id' => $record->id, 'compartment_id' => $id, 'loaded_liters' => $liters] + $data);
                } else {
                    UnloadItem::create(['trip_unload_id' => $record->id, 'load_item_id' => $id, 'delivered_liters' => $liters] + $data);
                }
            }

            return $record->refresh()->load('items');
        });
    }

    public function report(User $actor, string $tripId, string $type, array $information, DateTimeInterface $occurredAt): TripEvent
    {
        return $this->transaction(function () use ($actor, $tripId, $type, $information, $occurredAt): TripEvent {
            $this->lock();
            $trip = Trip::query()->lockForUpdate()->findOrFail($tripId);
            Gate::forUser($actor)->authorize('report-trip-information', $trip);

            return TripEvent::create(['trip_id' => $trip->id, 'type' => $type, 'information' => $information, 'created_by_id' => $actor->id, 'occurred_at' => CarbonImmutable::instance($occurredAt)->utc()]);
        });
    }

    public function reading(User $actor, string $tripId, string $assignmentId, string $type, string $value, DateTimeInterface $occurredAt, string $reason, array $evidence = []): TripOdometerReading
    {
        return $this->transaction(function () use ($actor, $tripId, $assignmentId, $type, $value, $occurredAt, $reason, $evidence): TripOdometerReading {
            $this->lock();
            $trip = Trip::query()->lockForUpdate()->findOrFail($tripId);
            Gate::forUser($actor)->authorize('report-trip-information', $trip);
            $this->validateReading($value);

            return TripOdometerReading::create(['trip_id' => $tripId, 'tractor_assignment_id' => $assignmentId, 'reading_type' => $type, 'reading_value' => $value]
                + $this->data($actor, $occurredAt, $reason, $evidence))->refresh();
        });
    }

    public function correctReading(User $actor, string $previousId, string $value, DateTimeInterface $occurredAt, string $reason, array $evidence = []): TripOdometerReading
    {
        return $this->transaction(function () use ($actor, $previousId, $value, $occurredAt, $reason, $evidence): TripOdometerReading {
            $this->lock();
            $previous = TripOdometerReading::query()->lockForUpdate()->findOrFail($previousId);
            $this->authorize($actor, $previous->trip_id);
            $this->validateReading($value);

            return TripOdometerReading::create(['trip_id' => $previous->trip_id, 'tractor_assignment_id' => $previous->tractor_assignment_id,
                'reading_type' => $previous->reading_type, 'reading_value' => $value, 'previous_reading_id' => $previous->id, 'revision' => $previous->revision + 1]
                + $this->data($actor, $occurredAt, $reason, $evidence))->refresh();
        });
    }

    public function distance(User $actor, string $tripId, string $assignmentId): ?string
    {
        Gate::forUser($actor)->authorize('manage-trip-operations');

        return DB::selectOne("SELECT CASE WHEN f.reading_value>=i.reading_value THEN (f.reading_value-i.reading_value)::text ELSE NULL END AS distance
            FROM trip_odometer_readings i JOIN trip_odometer_readings f ON f.trip_id=i.trip_id AND f.tractor_assignment_id=i.tractor_assignment_id
            WHERE i.trip_id=? AND i.tractor_assignment_id=? AND i.reading_type='initial' AND f.reading_type='final'
            AND NOT EXISTS (SELECT 1 FROM trip_odometer_readings x WHERE x.previous_reading_id=i.id)
            AND NOT EXISTS (SELECT 1 FROM trip_odometer_readings x WHERE x.previous_reading_id=f.id)", [$tripId, $assignmentId])?->distance;
    }

    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(873210)');
        DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
    }

    private function authorize(User $actor, string $tripId): Trip
    {
        $this->lock();
        Gate::forUser($actor)->authorize('manage-trip-operations');

        return Trip::query()->lockForUpdate()->findOrFail($tripId);
    }

    private function data(User $actor, DateTimeInterface $at, string $reason, array $evidence): array
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'El motivo es obligatorio.']);
        }
        $data = ['recorded_by_id' => $actor->id, 'occurred_at' => $at, 'reason' => $reason];
        if ($evidence !== []) {
            $data['evidence'] = $evidence;
        }

        return $data;
    }

    private function requireDetails(array $amounts): void
    {
        if ($amounts === []) {
            throw ValidationException::withMessages(['items' => 'Debe registrar al menos un detalle comunicado.']);
        }
    }

    private function validateReading(string $value): void
    {
        if (! preg_match('/\\A[0-9]+(?:\\.[0-9]+)?\\z/', $value)) {
            throw ValidationException::withMessages(['reading' => 'Lectura decimal no negativa requerida.']);
        }
    }

    private function snapshot(Trip $trip): array
    {
        return json_decode(DB::selectOne('SELECT to_jsonb(t)::text AS data FROM trips t WHERE id=?', [$trip->id])->data, true);
    }

    private function transaction(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['23514', '23505', '23503'], true)) {
                throw ValidationException::withMessages(['operation' => 'La operación incumple sus relaciones, capacidad o permisos.']);
            }
            throw $exception;
        }
    }
}
