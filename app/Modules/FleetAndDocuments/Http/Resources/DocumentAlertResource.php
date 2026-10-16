<?php

namespace App\Modules\FleetAndDocuments\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DocumentAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = ($request->attributes->get('document_alert_at') ?? CarbonImmutable::now('America/Costa_Rica'))->startOfDay();
        $days = (int) $today->diffInDays(CarbonImmutable::parse($this->expires_on->toDateString(), 'America/Costa_Rica'), false);

        return ['id' => $this->id, 'document_type_id' => $this->document_type_id, 'document_type' => $this->configurationSnapshot->name,
            'asset' => ['id' => $this->fleetAsset->id, 'code' => $this->fleetAsset->code, 'type' => $this->fleetAsset->type],
            'expires_on' => $this->expires_on->toDateString(), 'days_remaining' => $days, 'status' => $this->validity($today)->value,
            'review' => $this->reviewed_at === null ? null : ['at' => $this->reviewed_at, 'by' => ['id' => (string) $this->reviewed_by_id, 'name' => $this->reviewer_name], 'validity_at_review' => $this->validity_at_review],
            'original_href' => '/api/documents/'.$this->id];
    }
}
