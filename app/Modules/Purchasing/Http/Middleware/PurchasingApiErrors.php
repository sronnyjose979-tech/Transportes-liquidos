<?php

namespace App\Modules\Purchasing\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PurchasingApiErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (QueryException $exception) {
            Log::warning('purchasing.database_operation_rejected', ['sqlstate' => $exception->errorInfo[0] ?? null]);
            $response = response()->json(['message' => 'No se pudo confirmar la operación. Consulta el estado antes de reenviar.'], 500);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
