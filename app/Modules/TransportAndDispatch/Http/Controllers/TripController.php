<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TransportAndDispatch\Application\TransportAdministration;
use App\Modules\TransportAndDispatch\Http\Requests\TransportQueryRequest;
use App\Modules\TransportAndDispatch\Http\Requests\TripActionRequest;
use App\Modules\TransportAndDispatch\Http\Requests\TripCreateRequest;
use App\Modules\TransportAndDispatch\Http\Resources\TransportRecordResource;
use App\Modules\TransportAndDispatch\Http\Resources\TripResource;
use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Http\JsonResponse;

class TripController extends Controller
{
    public function index(TransportQueryRequest $request): JsonResponse
    {
        $query = Trip::query();
        foreach (['transport_request_id', 'execution_status', 'administrative_status'] as $field) {
            if ($request->validated($field) !== null) {
                $query->where($field, $request->validated($field));
            }
        }

        return TripResource::collection($query->orderByDesc('registered_at')->orderBy('id')->paginate($request->validated('per_page', 25)))->response();
    }

    public function show(TransportQueryRequest $request, string $id): TripResource
    {
        return new TripResource(Trip::query()->with(['transportRequest', 'tripLoad.items', 'unload.items',
            'driverAssignments' => fn ($q) => $q->current()->with('release'), 'assetAssignments' => fn ($q) => $q->current()->with('release')])->findOrFail($id));
    }

    public function store(TripCreateRequest $request, TransportAdministration $service): JsonResponse
    {
        return (new TripResource($service->createTrip($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function action(TripActionRequest $request, TransportAdministration $service, string $id): TripResource
    {
        $action = substr($request->route()->getName(), strlen('transport.trips.'));

        return new TripResource($service->tripAction($request->user(), $id, $action, $request->validated()));
    }

    public function history(TransportQueryRequest $request, string $id): JsonResponse
    {
        return TransportRecordResource::collection(Trip::findOrFail($id)->changes()->orderBy('to_version')->paginate($request->validated('per_page', 25)))->response();
    }

    public function warnings(TransportQueryRequest $request, string $id): JsonResponse
    {
        return TransportRecordResource::collection(Trip::findOrFail($id)->warnings()->with('resolutions')->orderBy('captured_at')->orderBy('id')->paginate($request->validated('per_page', 25)))->response();
    }
}
