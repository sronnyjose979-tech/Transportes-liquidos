<?php

namespace App\Modules\TransportAndDispatch\Application;

use App\Modules\TransportAndDispatch\Models\Trip;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Optimistic capture context, checked under the existing transport transaction lock. */
final class DriverOfflineContext
{
    public static function token(Trip $trip): string
    {
        $trip->loadMissing([
            'assetAssignments' => fn ($q) => $q->current()->whereRaw('starts_at <= clock_timestamp()'),
            'driverAssignments' => fn ($q) => $q->current()->whereRaw('starts_at <= clock_timestamp()'),
        ]);

        return hash('sha256', json_encode([$trip->id, $trip->version, $trip->execution_status, $trip->administrative_status,
            $trip->assetAssignments->pluck('id')->sort()->values()->all(),
            $trip->driverAssignments->pluck('id')->sort()->values()->all()], JSON_THROW_ON_ERROR));
    }

    public static function check(Trip $trip, ?string $expected): void
    {
        if ($expected !== null && ! hash_equals(self::token($trip), $expected)) {
            throw new ConflictHttpException('El estado o las asignaciones del viaje cambiaron desde la consulta. Conserva la captura para revisión.');
        }
    }
}
