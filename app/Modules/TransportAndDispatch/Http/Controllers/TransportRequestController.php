<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TransportAndDispatch\Application\TransportAdministration;
use App\Modules\TransportAndDispatch\Http\Requests\TransportQueryRequest;
use App\Modules\TransportAndDispatch\Http\Requests\TransportRequestWrite;
use App\Modules\TransportAndDispatch\Http\Resources\TransportRecordResource;
use App\Modules\TransportAndDispatch\Http\Resources\TransportRequestResource;
use App\Modules\TransportAndDispatch\Models\TransportRequest;
use Illuminate\Http\JsonResponse;

class TransportRequestController extends Controller
{
    public function index(TransportQueryRequest $request): JsonResponse
    {
        return TransportRequestResource::collection(TransportRequest::query()->withCount('trips')->orderByDesc('registered_at')->orderBy('id')->paginate($request->validated('per_page', 25)))->response();
    }

    public function show(TransportQueryRequest $request, string $id): TransportRequestResource
    {
        return new TransportRequestResource(TransportRequest::query()->withCount('trips')->findOrFail($id));
    }

    public function store(TransportRequestWrite $request, TransportAdministration $service): JsonResponse
    {
        return (new TransportRequestResource($service->createRequest($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(TransportRequestWrite $request, TransportAdministration $service, string $id): TransportRequestResource
    {
        return new TransportRequestResource($service->updateRequest($request->user(), $id, $request->validated()));
    }

    public function history(TransportQueryRequest $request, string $id): JsonResponse
    {
        return TransportRecordResource::collection(TransportRequest::findOrFail($id)->changes()->orderBy('to_version')->paginate($request->validated('per_page', 25)))->response();
    }
}
