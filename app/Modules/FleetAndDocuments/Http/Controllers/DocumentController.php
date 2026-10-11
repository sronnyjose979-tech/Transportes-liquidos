<?php

namespace App\Modules\FleetAndDocuments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FleetAndDocuments\Application\DocumentAdministration;
use App\Modules\FleetAndDocuments\Domain\DocumentValidity;
use App\Modules\FleetAndDocuments\Http\Requests\DocumentQueryRequest;
use App\Modules\FleetAndDocuments\Http\Requests\DocumentUploadRequest;
use App\Modules\FleetAndDocuments\Http\Resources\DocumentResource;
use App\Modules\FleetAndDocuments\Http\Resources\DocumentTypeResource;
use App\Modules\FleetAndDocuments\Infrastructure\PrivateDocumentStorage;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(DocumentQueryRequest $request, DocumentAdministration $service, ?string $assetId = null): JsonResponse
    {
        if ($assetId !== null) {
            FleetAsset::query()->findOrFail($assetId);
        }
        $query = $service->documents();
        // Asset history includes previous documents. Ordinary lists/alerts use explicit current references.
        if (! $request->routeIs('fleet.documents.history')) {
            $query->current();
        }
        $asset = $assetId ?? $request->validated('fleet_asset_id');
        if ($asset !== null) {
            $query->where('fleet_asset_id', $asset);
        }
        if ($type = $request->validated('document_type_id')) {
            $query->where('document_type_id', $type);
        }
        if ($validity = $request->validated('validity')) {
            $query->withValidity(DocumentValidity::from($validity));
        }

        return DocumentResource::collection($query->orderByDesc('registered_at')->orderBy('id')->paginate($request->validated('per_page', 25)))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function missing(DocumentQueryRequest $request, string $assetId): JsonResponse
    {
        $asset = FleetAsset::query()->findOrFail($assetId);

        return DocumentTypeResource::collection(DocumentType::query()->missingFor($asset)->orderBy('id')->paginate($request->validated('per_page', 25)))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function show(DocumentQueryRequest $request, DocumentAdministration $service, string $id): JsonResponse
    {
        return (new DocumentResource($service->documents()->findOrFail($id)))->response()->header('Cache-Control', 'no-store');
    }

    public function store(DocumentUploadRequest $request, DocumentAdministration $service, string $assetId): JsonResponse
    {
        return $this->created($service->register($request->user(), $assetId, $request->safe()->except('file'), $request->file('file')));
    }

    public function renew(DocumentUploadRequest $request, DocumentAdministration $service, string $assetId, string $id): JsonResponse
    {
        return $this->created($service->register($request->user(), $assetId, $request->safe()->except('file'), $request->file('file'), $id));
    }

    private function created(Document $document): JsonResponse
    {
        return (new DocumentResource($document))->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function download(DocumentQueryRequest $request, DocumentAdministration $service, PrivateDocumentStorage $storage, string $id): StreamedResponse
    {
        return $storage->download($service->documents()->findOrFail($id));
    }
}
