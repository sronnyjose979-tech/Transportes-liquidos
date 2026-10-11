<?php

namespace App\Modules\FleetAndDocuments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $snapshot = $this->configurationSnapshot;

        return [
            'id' => $this->id,
            'fleet_asset_id' => $this->fleet_asset_id,
            'document_type_id' => $this->document_type_id,
            'configuration' => [
                'name' => $snapshot->name,
                'tracks_expiration' => $snapshot->tracks_expiration,
                'required_for_tractor_units' => $snapshot->required_for_tractor_units,
                'required_for_tankers' => $snapshot->required_for_tankers,
            ],
            'issued_on' => $this->issued_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'registered_at' => $this->registered_at->setTimezone('America/Costa_Rica')->toIso8601String(),
            'uploaded_by_id' => $this->uploaded_by_id,
            'is_current' => (bool) $this->is_current,
            'validity' => $this->resource->validity()->value,
        ];
    }
}
