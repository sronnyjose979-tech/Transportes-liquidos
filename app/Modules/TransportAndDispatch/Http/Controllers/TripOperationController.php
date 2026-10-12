<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TransportAndDispatch\Application\TripOperations;
use App\Modules\TransportAndDispatch\Http\Requests\OdometerWriteRequest;
use App\Modules\TransportAndDispatch\Http\Requests\OperationWriteRequest;
use App\Modules\TransportAndDispatch\Http\Requests\ReportWriteRequest;
use App\Modules\TransportAndDispatch\Http\Requests\TransportQueryRequest;
use App\Modules\TransportAndDispatch\Http\Resources\TransportRecordResource;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripOdometerReading;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripOperationController extends Controller
{
    public function store(OperationWriteRequest $request, TripOperations $service, string $id): JsonResponse
    {
        $data = $request->validated();
        $loading = $request->routeIs('transport.operations.load');
        $amounts = array_column($data['items'], 'liters', $loading ? 'compartment_id' : 'load_item_id');
        $record = $service->{$loading ? 'load' : 'unload'}($request->user(), $id, $data['operation_site_id'], $amounts,
            CarbonImmutable::parse($data['occurred_at']), $data['reason'], $data['evidence'] ?? []);

        return (new TransportRecordResource($record))->response()->setStatusCode(201);
    }

    public function show(TransportQueryRequest $request, string $id): TransportRecordResource
    {
        $trip = Trip::findOrFail($id);
        $relation = $request->routeIs('transport.operations.show-load') ? $trip->tripLoad() : $trip->unload();

        return new TransportRecordResource($relation->with('items')->firstOrFail());
    }

    public function report(ReportWriteRequest $request, TripOperations $service, string $id): JsonResponse
    {
        $data = $request->validated();

        return (new TransportRecordResource($service->report($request->user(), $id, $data['type'], $data['information'], CarbonImmutable::parse($data['occurred_at']))))->response()->setStatusCode(201);
    }

    public function reports(TransportQueryRequest $request, string $id): JsonResponse
    {
        return TransportRecordResource::collection(Trip::findOrFail($id)->events()->orderBy('registered_at')->orderBy('id')->paginate($request->validated('per_page', 25)))->response();
    }

    public function reading(OdometerWriteRequest $request, TripOperations $service, string $id): JsonResponse
    {
        $data = $request->validated();

        return (new TransportRecordResource($service->reading($request->user(), $id, $data['tractor_assignment_id'], $data['reading_type'],
            $data['reading_value'], CarbonImmutable::parse($data['occurred_at']), $data['reason'], $data['evidence'] ?? [])))->response()->setStatusCode(201);
    }

    public function correct(OdometerWriteRequest $request, TripOperations $service, string $id, string $readingId): JsonResponse
    {
        TripOdometerReading::query()->where('trip_id', $id)->findOrFail($readingId);
        $data = $request->validated();

        return (new TransportRecordResource($service->correctReading($request->user(), $readingId, $data['reading_value'], CarbonImmutable::parse($data['occurred_at']), $data['reason'], $data['evidence'] ?? [])))->response()->setStatusCode(201);
    }

    public function readings(TransportQueryRequest $request, string $id): JsonResponse
    {
        return TransportRecordResource::collection(Trip::findOrFail($id)->odometerReadings()->orderBy('registered_at')->orderBy('id')->paginate($request->validated('per_page', 25)))->response();
    }

    public function distance(TransportQueryRequest $request, TripOperations $service, string $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);
        $assignment = $request->validated('tractor_assignment_id');
        if ($assignment === null) {
            throw ValidationException::withMessages(['tractor_assignment_id' => 'Asignación histórica requerida.']);
        }
        $trip->assetAssignments()->where('asset_type', 'tractor_unit')->findOrFail($assignment);

        return response()->json(['data' => ['tractor_assignment_id' => $assignment, 'distance' => $service->distance($request->user(), $id, $assignment)]]);
    }

    public function differences(TransportQueryRequest $request, string $id): JsonResponse
    {
        Trip::findOrFail($id);
        $content = DB::selectOne('SELECT trip_volume_content(?)::text AS content', [$id])->content;

        return response()->json(['data' => ['difference' => $content === null ? null : json_decode($content,true)]]);
    }
}
