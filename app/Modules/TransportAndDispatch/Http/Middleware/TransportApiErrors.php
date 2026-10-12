<?php

namespace App\Modules\TransportAndDispatch\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TransportApiErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public static function databaseError(QueryException $exception): ?Response
    {
        $state = $exception->errorInfo[0] ?? null;
        $response = match (true) {
            in_array($state, ['40001', '40P01', '55P03'], true) => response()->json(['message' => 'Conflicto concurrente; consulte el estado antes de reintentar.'], 409),
            in_array($state, ['23505', '23514', '23503', '22003'], true) => response()->json(['message' => 'La operación incumple integridad, relaciones o autorizaciones.', 'errors' => ['operation' => ['Revise los datos y el estado actual.']]], 422),
            default => response()->json(['message' => 'No se pudo completar la operación. Consulte su estado antes de reintentar.'], 500),
        };
        $response?->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
