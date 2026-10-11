<?php

namespace App\Modules\AccessAndPersonnel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        // Re-read state even when the guard already holds an authenticated instance.
        $user = $request->user()?->fresh();
        if (! $user?->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'La cuenta no está activa.');
        }
        Auth::guard('web')->setUser($user);

        return $next($request);
    }
}
