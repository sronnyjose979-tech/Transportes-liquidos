<?php

namespace App\Modules\FleetAndDocuments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FleetAndDocuments\Application\DocumentAdministration;
use App\Modules\FleetAndDocuments\Http\Requests\DocumentQueryRequest;
use App\Modules\FleetAndDocuments\Http\Requests\DocumentTypeRequest;
use App\Modules\FleetAndDocuments\Http\Resources\DocumentTypeResource;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use Illuminate\Http\JsonResponse;

class DocumentTypeController extends Controller
{
    public function index(DocumentQueryRequest $request): JsonResponse
    {
        return DocumentTypeResource::collection(DocumentType::query()->orderBy('id')->paginate($request->validated('per_page', 25)))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(DocumentTypeRequest $request, DocumentAdministration $service): JsonResponse
    {
        return (new DocumentTypeResource($service->configure($request->user(), $request->validated())))
            ->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(DocumentTypeRequest $request, DocumentAdministration $service, string $id): JsonResponse
    {
        return (new DocumentTypeResource($service->configure($request->user(), $request->validated(), $id)))
            ->response()->header('Cache-Control', 'no-store');
    }
}
