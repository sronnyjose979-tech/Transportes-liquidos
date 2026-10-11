<?php

namespace App\Modules\FleetAndDocuments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tracks_expiration' => $this->tracks_expiration,
            'required_for_tractor_units' => $this->required_for_tractor_units,
            'required_for_tankers' => $this->required_for_tankers,
        ];
    }
}
