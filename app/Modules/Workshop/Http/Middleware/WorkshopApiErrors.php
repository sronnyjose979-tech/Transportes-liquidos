<?php

namespace App\Modules\Workshop\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkshopApiErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public static function databaseError(QueryException $exception): Response
    {
        $state = $exception->errorInfo[0] ?? null;
        $status = match (true) {
            in_array($state, ['40001', '40P01', '55P03', '23505'], true) => 409,
            in_array($state, ['23514', '23503', '23502', '22003', '22007', '22P02', 'P0002'], true) => 422,
            str_starts_with((string) $state, '08'), $state === '57014' => 503,
            default => 500,
        };

        return response()->json(['message' => $status === 409 ? 'Conflicto con el estado confirmado; conserve la clave de idempotencia.' : ($status === 422 ? 'La operación incumple sus datos, relaciones, autorización o estado actual.' : 'No se pudo confirmar el resultado; consulte el recibo con la misma clave.'), 'errors' => ['operation' => ['Revise el recurso y los datos antes de reintentar.']]], $status)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
