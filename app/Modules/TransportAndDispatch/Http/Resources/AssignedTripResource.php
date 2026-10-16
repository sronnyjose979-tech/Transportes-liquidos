<?php

namespace App\Modules\TransportAndDispatch\Http\Resources;

use App\Modules\TransportAndDispatch\Application\DriverOfflineContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignedTripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $route = $this->transportRequest->route;

        return ['id' => $this->id, 'offline_context' => DriverOfflineContext::token($this->resource), 'product' => $this->product_name_snapshot, 'execution_status' => $this->execution_status,
            'administrative_status' => $this->administrative_status, 'scheduled_start_at' => $this->scheduled_start_at,
            'scheduled_end_at' => $this->scheduled_end_at, 'officially_started_at' => $this->officially_started_at,
            'officially_completed_at' => $this->officially_completed_at,
            'route' => ['name' => $route->name, 'origin' => $route->originSite->name, 'destination' => $route->destinationSite->name],
            'assets' => $this->assetAssignments->map(fn ($assignment) => ['id' => $assignment->id, 'fleet_asset_id' => $assignment->fleet_asset_id, 'starts_at' => $assignment->starts_at, 'type' => $assignment->asset_type, 'code' => $assignment->fleetAsset->code]),
            'my_odometer' => $this->whenLoaded('odometerReadings', fn () => TransportRecordResource::collection($this->odometerReadings)),
            'my_reports' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => ['id' => $event->id, 'type' => $event->type, 'information' => $event->information, 'occurred_at' => $event->occurred_at, 'registered_at' => $event->registered_at]))];
    }
}
