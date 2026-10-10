<?php

namespace App\Modules\AccessAndPersonnel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:4096']], ['required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.', 'max' => 'El campo :attribute supera el límite permitido.']);
        if (! Auth::guard('web')->attempt([...$credentials, 'is_active' => true])) {
            throw ValidationException::withMessages(['login' => 'Las credenciales no son válidas.']);
        }
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie(Auth::guard('web')->user()->getAuthPassword()));

        return $this->show($request);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => ['id' => (string) $user->id, 'login' => $user->login, 'roles' => $user->roles()->pluck('name')]])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
