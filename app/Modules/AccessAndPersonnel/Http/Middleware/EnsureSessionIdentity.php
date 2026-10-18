<?php

namespace App\Modules\AccessAndPersonnel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Detect stale UI identity; this header never authenticates or grants permissions. */
class EnsureSessionIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = (string) $request->user()->id;
        $expected = $request->header('X-Session-User');
        if ($expected !== null && $expected !== $actor) {
            return response()->json(['message' => 'La sesión cambió. Vuelve a cargar antes de continuar.', 'code' => 'session_changed'], 409)
                ->header('X-Session-User', $actor)->header('Cache-Control', 'private, no-store');
        }

        $response = $next($request);
        $response->headers->set('X-Session-User', $actor);

        return $response;
    }
}
