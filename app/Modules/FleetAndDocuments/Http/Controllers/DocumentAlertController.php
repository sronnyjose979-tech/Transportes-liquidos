<?php

namespace App\Modules\FleetAndDocuments\Http\Controllers;

use App\Modules\FleetAndDocuments\Application\DocumentAlerts;
use App\Modules\FleetAndDocuments\Http\Requests\DocumentAlertQueryRequest;
use App\Modules\FleetAndDocuments\Http\Requests\DocumentAlertReviewRequest;
use App\Modules\FleetAndDocuments\Http\Resources\DocumentAlertResource;
use Carbon\CarbonImmutable;

final class DocumentAlertController
{
    public function index(DocumentAlertQueryRequest $request, DocumentAlerts $alerts)
    {
        $at = CarbonImmutable::now('America/Costa_Rica');
        $request->attributes->set('document_alert_at', $at);

        return DocumentAlertResource::collection($alerts->query($request->validated(), $at)->orderBy('documents.expires_on')->orderBy('documents.id')->paginate($request->validated('per_page', 25)));
    }

    public function summary(DocumentAlertQueryRequest $request, DocumentAlerts $alerts)
    {
        return response()->json(['data' => $alerts->summary()]);
    }

    public function review(DocumentAlertReviewRequest $request, DocumentAlerts $alerts, string $id)
    {
        return response()->json(['data' => $alerts->review($request->user(), $id)]);
    }
}
