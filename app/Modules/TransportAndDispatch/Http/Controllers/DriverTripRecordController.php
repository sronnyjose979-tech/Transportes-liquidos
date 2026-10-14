<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TransportAndDispatch\Application\DriverTripRecords;
use App\Modules\TransportAndDispatch\Http\Requests\AssignedTripQueryRequest;
use App\Modules\TransportAndDispatch\Http\Requests\DriverRecordWriteRequest;
use App\Modules\TransportAndDispatch\Http\Resources\TransportRecordResource;
use App\Modules\TransportAndDispatch\Models\TripEvent;
use App\Modules\TransportAndDispatch\Models\TripOdometerReading;
use Illuminate\Http\JsonResponse;

class DriverTripRecordController extends Controller
{
    public function history(AssignedTripQueryRequest $request): JsonResponse
    {
        $filters = $request->validate(['trip_id' => ['sometimes', 'uuid']]);
        $reports = TripEvent::query()->where('created_by_id', $request->user()->id);
        $readings = TripOdometerReading::query()->where('recorded_by_id', $request->user()->id);
        if (isset($filters['trip_id'])) {
            $reports->where('trip_id', $filters['trip_id']);
            $readings->where('trip_id', $filters['trip_id']);
        }

        $size = (int) $request->validated('per_page', 25);
        $page = (int) $request->validated('page', 1);
        $events = $reports->orderByDesc('registered_at')->orderBy('id')->paginate($size, ['*'], 'page', $page);
        $odometer = $readings->orderByDesc('registered_at')->orderBy('id')->paginate($size, ['*'], 'page', $page);

        return response()->json(['data' => ['reports' => TransportRecordResource::collection($events->getCollection()),
            'odometer' => TransportRecordResource::collection($odometer->getCollection()),
            'meta' => ['current_page' => $page, 'last_page' => max($events->lastPage(), $odometer->lastPage())]]]);
    }

    public function store(DriverRecordWriteRequest $request, DriverTripRecords $service, string $id): JsonResponse
    {
        $kind = $request->routeIs('transport.driver.odometer') ? 'odometer' : 'report';

        return (new TransportRecordResource($service->write($request->user(), $id, $kind, $request->validated())))->response()->setStatusCode(201);
    }
}
