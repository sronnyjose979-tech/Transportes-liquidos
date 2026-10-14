<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TransportAndDispatch\Http\Requests\AssignedTripQueryRequest;
use App\Modules\TransportAndDispatch\Http\Resources\AssignedTripResource;
use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AssignedTripController extends Controller
{
    private function query(AssignedTripQueryRequest $request): Builder
    {
        $query = Trip::query()->with(['transportRequest.route.originSite', 'transportRequest.route.destinationSite', 'assetAssignments' => fn ($q) => $q->current()->whereRaw('starts_at <= clock_timestamp()')->with('fleetAsset')]);
        if (! $request->user()->can('manage-personnel')) {
            $query->whereHas('driverAssignments', fn ($q) => $q->current()->whereRaw('starts_at <= clock_timestamp()')->whereHas('employee', fn ($e) => $e->where('user_id', $request->user()->id)->where('is_active', true)));
        }

        return $query;
    }

    public function index(AssignedTripQueryRequest $request): JsonResponse
    {
        return AssignedTripResource::collection($this->query($request)->orderByDesc('registered_at')->orderBy('id')->paginate($request->validated('per_page', 25)))->response();
    }

    public function show(AssignedTripQueryRequest $request, string $id): AssignedTripResource
    {
        return new AssignedTripResource($this->query($request)->with(['events' => fn ($q) => $q->where('created_by_id', $request->user()->id)->orderByDesc('registered_at')->limit(100), 'odometerReadings' => fn ($q) => $q->where('recorded_by_id', $request->user()->id)->orderByDesc('registered_at')->limit(100)])->findOrFail($id));
    }
}
