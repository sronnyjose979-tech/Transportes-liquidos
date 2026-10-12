<?php

namespace App\Modules\TransportAndDispatch\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [];
        foreach (['id', 'transport_request_id', 'liquid_product_id', 'product_name_snapshot', 'planning_status', 'execution_status',
            'administrative_status', 'scheduled_start_at', 'scheduled_end_at', 'officially_started_at', 'officially_completed_at', 'closed_at',
            'cancelled_at', 'started_by_id', 'completed_by_id', 'closed_by_id', 'cancelled_by_id', 'cancellation_reason', 'registered_by_id', 'registered_at', 'version'] as $field) {
            $data[$field] = $this->$field;
        }
        $data['request'] = new TransportRequestResource($this->whenLoaded('transportRequest'));
        $data['load'] = new TransportRecordResource($this->whenLoaded('tripLoad'));
        $data['unload'] = new TransportRecordResource($this->whenLoaded('unload'));
        $data['current_driver_assignments'] = TransportRecordResource::collection($this->whenLoaded('driverAssignments'));
        $data['current_asset_assignments'] = TransportRecordResource::collection($this->whenLoaded('assetAssignments'));

        return $data;
    }
}
