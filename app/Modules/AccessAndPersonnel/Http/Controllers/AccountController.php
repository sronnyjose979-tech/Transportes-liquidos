<?php

namespace App\Modules\AccessAndPersonnel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessAndPersonnel\Application\AccountAdministration;
use App\Modules\AccessAndPersonnel\Http\Requests\AccountRequest;
use App\Modules\AccessAndPersonnel\Http\Resources\AccountResource;
use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']], ['integer' => 'El campo :attribute debe ser entero.', 'min' => 'El campo :attribute es demasiado pequeño.', 'max' => 'El campo :attribute supera el límite permitido.']);

        return AccountResource::collection(User::query()->with('roles', 'employee')->orderBy('id')->paginate($data['per_page'] ?? 25))->response()->header('Cache-Control', 'no-store');
    }

    public function show(string $id): JsonResponse
    {
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            abort(404);
        }

        return (new AccountResource(User::query()->with('roles', 'employee')->findOrFail($id)))->response()->header('Cache-Control', 'no-store');
    }

    public function store(AccountRequest $request, AccountAdministration $service): JsonResponse
    {
        return (new AccountResource($service->save($request->user(), $request->safe()->except('password_confirmation'))))->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(AccountRequest $request, AccountAdministration $service, string $id): JsonResponse
    {
        $user = $service->save($request->user(), $request->safe()->except('password_confirmation'), (int) $id);
        if ((string) $request->user()->id === (string) $user->id && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return (new AccountResource($user))->response()->header('Cache-Control', 'no-store');
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->whereIn('name', Role::SUPPORTED_NAMES)->orderBy('name')->get(['id', 'name'])])->header('Cache-Control', 'no-store');
    }
}
