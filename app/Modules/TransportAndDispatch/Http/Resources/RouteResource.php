<?php

namespace App\Modules\TransportAndDispatch\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'origin_site_id' => $this->origin_site_id,
            'destination_site_id' => $this->destination_site_id, 'has_requests' => (bool) $this->transport_requests_exists];
    }
}
