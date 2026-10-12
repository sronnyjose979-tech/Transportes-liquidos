<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\TransportAndDispatch\Http\Requests\TransportQueryRequest;
use App\Modules\TransportAndDispatch\Http\Resources\TransportLookupResource;
use App\Modules\TransportAndDispatch\Models\Customer;
use App\Modules\TransportAndDispatch\Models\LiquidProduct;
use App\Modules\TransportAndDispatch\Models\OperationSite;
use App\Modules\TransportAndDispatch\Models\Route;
use Illuminate\Http\JsonResponse;

class TransportLookupController extends Controller
{
    public function index(TransportQueryRequest $request, string $kind): JsonResponse
    {
        $query = match ($kind) {
            'customers' => Customer::query(), 'products' => LiquidProduct::query()->where('is_active', true),
            'sites' => OperationSite::query(), 'routes' => Route::query(),
            'drivers' => Employee::query()->where('is_active', true)->whereHas('user', fn ($q) => $q->where('is_active', true)->whereHas('roles', fn ($r) => $r->where('name', 'driver'))),
            'assets' => FleetAsset::query()->with('tanker.compartments'),
            default => abort(404),
        };

        return TransportLookupResource::collection($query->orderBy('id')->paginate($request->validated('per_page', 25)))->response();
    }
}
