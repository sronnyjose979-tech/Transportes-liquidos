<?php

namespace App\Modules\FleetAndDocuments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FleetAndDocuments\Http\Requests\FleetAssetLookupRequest;
use App\Modules\FleetAndDocuments\Http\Resources\FleetAssetLookupResource;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use Illuminate\Http\JsonResponse;

class FleetAssetLookupController extends Controller
{
    public function index(FleetAssetLookupRequest $request): JsonResponse
    {
        return FleetAssetLookupResource::collection(FleetAsset::query()->select(['id', 'code', 'type'])
            ->orderBy('code')->orderBy('id')->paginate($request->validated('per_page', 25)))
            ->response()->header('Cache-Control', 'no-store');
    }
}
