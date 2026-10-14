<?php

namespace App\Modules\Inventory\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class InventoryApiErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (QueryException $error) {
            Log::warning('inventory.database_operation_rejected', ['sqlstate' => $error->errorInfo[0] ?? null]);
            $state = $error->errorInfo[0] ?? null;
            $status = in_array($state, ['23505', '40001', '40P01', '55P03'], true) ? 409 : (in_array($state, ['23514', '23503', '23502'], true) ? 422 : 500);
            $response = response()->json(['message' => 'No se pudo confirmar la operación. Consulta el estado y las cantidades antes de reenviar.'], $status);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
