<?php

namespace App\Modules\TransportAndDispatch\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportLookupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fields = match ($this->resource->getTable()) {
            'customers','liquid_products' => ['id', 'name'],
            'operation_sites' => ['id', 'name'],
            'routes' => ['id', 'name', 'origin_site_id', 'destination_site_id'],
            'employees' => ['id', 'name', 'is_active'],
            'fleet_assets' => ['id', 'code', 'type', 'service_status'],
        };
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $this->$field;
        }
        if ($this->resource->getTable() === 'fleet_assets' && $this->resource->relationLoaded('tanker')) {
            $data['compartments'] = $this->resource->tanker?->compartments->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'capacity_liters' => $c->capacity_liters, 'is_active' => $c->is_active])->all() ?? [];
        }

        return $data;
    }
}
