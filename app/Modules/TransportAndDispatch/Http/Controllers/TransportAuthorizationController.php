<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TransportAndDispatch\Application\TransportAdministration;
use App\Modules\TransportAndDispatch\Http\Requests\AuthorizationWriteRequest;
use App\Modules\TransportAndDispatch\Http\Requests\TransportQueryRequest;
use App\Modules\TransportAndDispatch\Http\Resources\TransportRecordResource;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationRequest;
use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Http\JsonResponse;

class TransportAuthorizationController extends Controller
{
    public function index(TransportQueryRequest $request, string $id): JsonResponse
    {
        return TransportRecordResource::collection(Trip::findOrFail($id)->authorizationRequests()->with('decision.application')->orderBy('registered_at')->orderBy('id')->paginate($request->validated('per_page', 25)))->response();
    }

    public function show(TransportQueryRequest $request, string $id): TransportRecordResource
    {
        return new TransportRecordResource(TransportAuthorizationRequest::query()->with('decision.application')->findOrFail($id));
    }

    public function store(AuthorizationWriteRequest $request, TransportAdministration $service, string $id): JsonResponse
    {
        return (new TransportRecordResource($service->requestAuthorization($request->user(), $id, $request->validated())))->response()->setStatusCode(201);
    }

    public function decide(AuthorizationWriteRequest $request, TransportAdministration $service, string $id): JsonResponse
    {
        return (new TransportRecordResource($service->decide($request->user(), $id, $request->validated())))->response()->setStatusCode(201);
    }
}
