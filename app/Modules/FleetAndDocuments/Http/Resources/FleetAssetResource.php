<?php

namespace App\Modules\FleetAndDocuments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FleetAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'code' => $this->code, 'type' => $this->type, 'service_status' => $this->service_status];
        if ($this->type === 'tanker') {
            $compartments = $this->tanker->compartments;
            $data['has_physical_partitions'] = $this->tanker->has_physical_partitions;
            $data['total_capacity_liters'] = $compartments->first()->tanker_total_capacity_liters;
            $data['compartments'] = $compartments->map(fn ($compartment): array => $compartment->only(['id', 'tanker_id', 'code', 'capacity_liters', 'is_active']))->all();
        }

        return $data;
    }
}
