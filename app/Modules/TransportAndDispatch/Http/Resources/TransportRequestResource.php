<?php

namespace App\Modules\TransportAndDispatch\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'customer_id' => $this->customer_id, 'liquid_product_id' => $this->liquid_product_id,
            'route_id' => $this->route_id, 'requested_liters' => $this->requested_liters, 'requested_at' => $this->requested_at,
            'registered_by_id' => $this->registered_by_id, 'registered_at' => $this->registered_at, 'version' => $this->version,
            'trips_count' => $this->whenCounted('trips')];
    }
}
