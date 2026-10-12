<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use App\Modules\TransportAndDispatch\Application\AssignmentAvailability;
use App\Modules\TransportAndDispatch\Http\Requests\AssignmentWriteRequest;
use App\Modules\TransportAndDispatch\Http\Requests\TransportQueryRequest;
use App\Modules\TransportAndDispatch\Http\Resources\TransportRecordResource;
use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class TripAssignmentController extends Controller
{
    public function write(AssignmentWriteRequest $request, AssignmentAdministration $service, string $id): JsonResponse
    {
        $data = $request->validated();
        $action = substr($request->route()->getName(), strlen('transport.assignments.'));
        $record = $action === 'release' ? $service->release($request->user(), $id, $data['resource_kind'], $data['reason'])
            : $service->{$action}($request->user(), $id, $data['resource_kind'], $data['resource_id'], $data['reason'], $data['decisions'] ?? []);

        return (new TransportRecordResource($record))->response()->setStatusCode(201);
    }

    public function history(TransportQueryRequest $request, string $id): JsonResponse
    {
        $query = Trip::findOrFail($id)->assignmentOperations()->with(['driverAssignment.release', 'assetAssignment.release', 'driverRelease', 'assetRelease', 'conflicts', 'applications']);

        return TransportRecordResource::collection($query->orderBy('registered_at')->orderBy('id')->paginate($request->validated('per_page', 25)))->response();
    }

    public function availability(TransportQueryRequest $request, AssignmentAvailability $service): JsonResponse
    {
        if ($request->validated('resource_kind') === null) {
            throw ValidationException::withMessages(['resource_kind' => 'Recurso requerido.']);
        }
        $data = $service->inspect($request->validated('resource_kind'), $request->validated('resource_id'));
        $data['conflicts'] = TransportRecordResource::collection($data['conflicts']);

        return response()->json(['data' => $data]);
    }
}
