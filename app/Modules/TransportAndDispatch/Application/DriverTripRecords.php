<?php

namespace App\Modules\TransportAndDispatch\Application;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripEvent;
use App\Modules\TransportAndDispatch\Models\TripOdometerReading;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DriverTripRecords
{
    public function write(User $actor, string $tripId, string $kind, array $data): Model
    {
        return DB::transaction(function () use ($actor, $tripId, $kind, $data): Model {
            DB::select('SELECT pg_advisory_xact_lock(873210)');
            DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
            $trip = Trip::query()->lockForUpdate()->findOrFail($tripId);
            $fresh = User::query()->lockForUpdate()->findOrFail($actor->id);
            DB::table('user_roles')->where('user_id', $fresh->id)->lockForUpdate()->get();
            Gate::forUser($fresh)->authorize('view-assigned-trips');
            $key = $data['request_key'];
            unset($data['request_key']);
            $fingerprint = hash('sha256', json_encode([$tripId, $kind, $data], JSON_THROW_ON_ERROR));
            $existing = DB::table('driver_operation_receipts')->where('actor_id', $actor->id)->where('request_key', $key)->first();
            if ($existing) {
                if ($existing->fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('Este identificador ya corresponde a otros datos.');
                }

                return ($existing->kind === 'report' ? TripEvent::query() : TripOdometerReading::query())->findOrFail($existing->record_id);
            }
            DriverOfflineContext::check($trip, $data['expected_trip_context'] ?? null);
            unset($data['expected_trip_context']);
            Gate::forUser($fresh)->authorize('report-trip-information', $trip);
            $operations = app(TripOperations::class);
            $at = CarbonImmutable::parse($data['occurred_at']);
            $record = $kind === 'report'
                ? $operations->report($actor, $tripId, $data['type'], $data['information'], $at)
                : $operations->reading($actor, $tripId, $data['tractor_assignment_id'], $data['reading_type'], $data['reading_value'], $at, $data['reason']);
            DB::table('driver_operation_receipts')->insert(['id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                'request_key' => $key, 'trip_id' => $tripId, 'kind' => $kind, 'fingerprint' => $fingerprint, 'record_id' => $record->id]);

            return $record;
        }, 3);
    }
}
