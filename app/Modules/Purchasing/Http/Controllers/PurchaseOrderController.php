<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Purchasing\Application\PurchaseFulfillment;
use App\Modules\Purchasing\Http\Requests\PurchaseFulfillmentRequest;
use App\Modules\Purchasing\Http\Resources\PurchaseOrderResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Workshop\Application\WorkshopAccess;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        app(WorkshopAccess::class)->office($request->user());
        $d = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return PurchaseOrderResource::collection(PurchaseOrder::with(PurchaseFulfillment::RELATIONS)->orderByDesc('registered_at')->orderBy('id')->paginate($d['per_page'] ?? 20));
    }

    public function show(Request $request, string $id): PurchaseOrderResource
    {
        app(WorkshopAccess::class)->office($request->user());

        return new PurchaseOrderResource(PurchaseOrder::with(PurchaseFulfillment::RELATIONS)->findOrFail($id));
    }

    public function store(PurchaseFulfillmentRequest $request, PurchaseFulfillment $service)
    {
        return (new PurchaseOrderResource($service->record($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function receive(PurchaseFulfillmentRequest $request, string $id, PurchaseFulfillment $service): PurchaseOrderResource
    {
        return new PurchaseOrderResource($service->record($request->user(), $request->validated(), $id));
    }

    public function lookups(Request $request)
    {
        app(WorkshopAccess::class)->office($request->user());

        return response()->json(['data' => ['suppliers' => Supplier::orderBy('name')->get(['id', 'name']), 'parts' => SparePart::orderBy('code')->get(['id', 'code', 'description', 'unit'])]]);
    }
}
