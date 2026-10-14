<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Application\PurchaseRequestAdministration;
use App\Modules\Purchasing\Http\Requests\PurchaseWriteRequest;
use App\Modules\Purchasing\Http\Resources\PurchaseRequestResource;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return PurchaseRequestResource::collection(PurchaseRequest::with(PurchaseRequestAdministration::RELATIONS)->orderByDesc('registered_at')->orderBy('id')->paginate($data['per_page'] ?? 20));
    }

    public function show(string $id): PurchaseRequestResource
    {
        return new PurchaseRequestResource(PurchaseRequest::with(PurchaseRequestAdministration::RELATIONS)->findOrFail($id));
    }

    public function store(PurchaseWriteRequest $request, PurchaseRequestAdministration $service): JsonResponse
    {
        return (new PurchaseRequestResource($service->record($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function decide(PurchaseWriteRequest $request, string $id, PurchaseRequestAdministration $service): PurchaseRequestResource
    {
        return new PurchaseRequestResource($service->record($request->user(), $request->validated(), $id));
    }
}
