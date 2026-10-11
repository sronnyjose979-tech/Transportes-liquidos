<?php

namespace App\Modules\FleetAndDocuments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Http\Requests\FleetAssetRequest;
use App\Modules\FleetAndDocuments\Http\Resources\FleetAssetResource;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class FleetController extends Controller
{
    abstract protected function assetType(): string;

    private function units(): Builder
    {
        return FleetAsset::query()->where('type', $this->assetType())
            ->with(['tanker.compartments' => fn (HasMany $query) => $query->withTankerCapacity()]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']], [
            'integer' => 'El campo :attribute debe ser entero.',
            'min' => 'El campo :attribute está por debajo del límite permitido.',
            'max' => 'El campo :attribute supera el límite permitido.',
        ]);

        return FleetAssetResource::collection($this->units()->orderBy('id')->paginate($data['per_page'] ?? 25))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function show(string $id): JsonResponse
    {
        return (new FleetAssetResource($this->units()->findOrFail($id)))->response()->header('Cache-Control', 'no-store');
    }

    public function store(FleetAssetRequest $request, FleetAdministration $service): JsonResponse
    {
        return (new FleetAssetResource($service->save($request->user(), $this->assetType(), $request->validated())))
            ->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(FleetAssetRequest $request, FleetAdministration $service, string $id): JsonResponse
    {
        return (new FleetAssetResource($service->save($request->user(), $this->assetType(), $request->validated(), $id)))
            ->response()->header('Cache-Control', 'no-store');
    }
}
