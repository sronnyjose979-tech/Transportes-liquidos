<?php

namespace App\Modules\TransportAndDispatch\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fields = match ($this->resource->getTable()) {
            'driver_assignments' => ['id', 'trip_id', 'employee_id', 'operation_id', 'starts_at'],
            'driver_releases' => ['id', 'assignment_id', 'operation_id', 'ends_at'],
            'trip_asset_assignments' => ['id', 'trip_id', 'fleet_asset_id', 'operation_id', 'asset_type', 'starts_at'],
            'trip_asset_releases' => ['id', 'assignment_id', 'operation_id', 'ends_at'],
            'transport_assignment_operations' => ['id', 'trip_id', 'action', 'resource_kind', 'payload', 'target_version', 'actor_id', 'reason', 'registered_at', 'execution_status_snapshot'],
            'transport_assignment_conflicts' => ['id', 'operation_id', 'driver_assignment_id', 'asset_assignment_id'],
            'transport_changes' => ['id', 'transport_request_id', 'trip_id', 'action', 'before_values', 'after_values', 'from_version', 'to_version', 'actor_id', 'reason', 'registered_at'],
            'transport_authorization_requests' => ['id', 'transport_request_id', 'trip_id', 'type', 'content', 'target_version', 'requested_by_id', 'reason', 'registered_at'],
            'transport_authorization_decisions' => ['id', 'authorization_request_id', 'decision', 'decided_by_id', 'reason', 'registered_at'],
            'transport_authorization_applications' => ['id', 'decision_id', 'change_id', 'operation_id', 'validation_type', 'applied_by_id', 'registered_at'],
            'trip_loads' => ['id', 'trip_id', 'tanker_assignment_id', 'operation_site_id', 'reason', 'evidence', 'recorded_by_id', 'registered_at', 'occurred_at'],
            'load_items' => ['id', 'trip_load_id', 'compartment_id', 'loaded_liters', 'capacity_liters_snapshot', 'compartment_code_snapshot', 'reason', 'evidence', 'recorded_by_id', 'registered_at', 'occurred_at'],
            'trip_unloads' => ['id', 'trip_id', 'trip_load_id', 'operation_site_id', 'reason', 'evidence', 'recorded_by_id', 'registered_at', 'occurred_at'],
            'unload_items' => ['id', 'trip_unload_id', 'load_item_id', 'delivered_liters', 'reason', 'evidence', 'recorded_by_id', 'registered_at', 'occurred_at'],
            'trip_events' => ['id', 'trip_id', 'type', 'information', 'created_by_id', 'registered_at', 'occurred_at'],
            'trip_odometer_readings' => ['id', 'trip_id', 'tractor_assignment_id', 'reading_type', 'reading_value', 'previous_reading_id', 'revision', 'recorded_by_id', 'reason', 'evidence', 'occurred_at', 'registered_at'],
            'trip_warnings' => ['id', 'trip_id', 'code', 'evidence', 'closure_version', 'captured_at'],
            'trip_warning_resolutions' => ['id', 'warning_id', 'action', 'evidence', 'actor_id', 'reason', 'registered_at'],
        };
        $data = [];
        foreach ($fields as $field) {
            $value = $this->$field;
            $data[$field] = is_array($value) ? $this->publicMetadata($value) : $value;
        }
        foreach (['items', 'conflicts', 'applications', 'resolutions'] as $relation) {
            if ($this->resource->relationLoaded($relation)) {
                $data[$relation] = self::collection($this->resource->getRelation($relation));
            }
        }
        foreach (['decision', 'application', 'release', 'driverAssignment', 'assetAssignment', 'driverRelease', 'assetRelease'] as $relation) {
            if ($this->resource->relationLoaded($relation)) {
                $data[$relation] = new self($this->resource->getRelation($relation));
            }
        }

        return $data;
    }

    private function publicMetadata(array $value): array
    {
        foreach ($value as $key => $item) {
            if (in_array($key, ['storage_key', 'storage_path', 'file_path', 'physical_path', 'absolute_path'], true)) {
                unset($value[$key]);
            } elseif (is_array($item)) {
                $value[$key] = $this->publicMetadata($item);
            }
        }

        return $value;
    }
}
