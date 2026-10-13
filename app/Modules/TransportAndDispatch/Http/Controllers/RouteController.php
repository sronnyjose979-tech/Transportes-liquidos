<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TransportAndDispatch\Application\RouteAdministration;
use App\Modules\TransportAndDispatch\Http\Requests\RouteWriteRequest;
use App\Modules\TransportAndDispatch\Http\Resources\RouteResource;
use App\Modules\TransportAndDispatch\Models\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'search' => ['sometimes', 'string', 'max:255']]);

        return RouteResource::collection(Route::query()->withExists('transportRequests')->when(isset($data['search']), fn ($query) => $query->whereRaw('strpos(lower(name), lower(?)) > 0', [$data['search']]))->orderBy('id')->paginate($data['per_page'] ?? 25))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function show(string $id): JsonResponse
    {
        return (new RouteResource(Route::query()->withExists('transportRequests')->findOrFail($id)))->response()->header('Cache-Control', 'no-store');
    }

    public function store(RouteWriteRequest $request, RouteAdministration $service): JsonResponse
    {
        return (new RouteResource($service->save($request->user(), $request->validated())))->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(RouteWriteRequest $request, RouteAdministration $service, string $id): JsonResponse
    {
        return (new RouteResource($service->save($request->user(), $request->validated(), $id)))->response()->header('Cache-Control', 'no-store');
    }
}
