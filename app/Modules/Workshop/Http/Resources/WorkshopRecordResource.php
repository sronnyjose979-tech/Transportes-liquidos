<?php

namespace App\Modules\Workshop\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkshopRecordResource extends JsonResource
{
    private const FIELDS = ['id', 'fleet_asset_id', 'work_order_id', 'admission_id', 'report_id', 'trip_event_id', 'description', 'kind', 'priority', 'status', 'version', 'created_at', 'created_by_id', 'reporter_id', 'recorded_by_id', 'occurred_at', 'registered_at', 'reason', 'root_record_id', 'revision', 'previous_report_id', 'previous_diagnosis_id', 'previous_scope_id', 'previous_activity_id', 'previous_event_id', 'previous_delivery_id', 'previous_usage_id', 'previous_record_id', 'previous_need_id', 'previous_cost_id', 'previous_attachment_id', 'linked_by_id', 'employee_id', 'starts_at', 'assigned_by_id', 'assignment_id', 'ends_at', 'mechanic_assignment_id', 'problem', 'probable_cause', 'proposed_repair', 'actor_id', 'scope_kind', 'diagnosis_id', 'proposed_by_id', 'scope_id', 'line_number', 'supplier_id', 'task_id', 'spare_part_id', 'quantity', 'unit_snapshot', 'description_snapshot', 'type', 'previous_request_id', 'content', 'target_version', 'requested_by_id', 'request_id', 'decision', 'decided_by_id', 'decision_id', 'operation_id', 'applied_by_id', 'observations', 'event_type', 'scope_part_id', 'delivered_to_assignment_id', 'delivery_id', 'confirmed_by_id', 'scope_task_id', 'performed_at', 'external_repair_id', 'category', 'mime', 'extension', 'size_bytes', 'checksum', 'uploaded_by_id', 'reservation_operation_id', 'usage_id', 'cost_kind', 'basis', 'amount', 'currency', 'source_attachment_id', 'covered_by_cost_id', 'effect', 'impediment_id', 'resolved_by_id', 'resolved_at', 'evidence', 'admitted_at', 'admitted_by_id', 'authorization_application_id', 'exited_at', 'reviewed_content', 'trip_asset_assignment_id', 'code', 'context', 'captured_by_id', 'service_status', 'is_open', 'is_effective', 'is_applied', 'is_invalidated', 'is_released'];

    public function toArray(Request $request): array
    {
        $values = $this->resource instanceof Model ? $this->resource->attributesToArray() : (array) $this->resource;
        $result = array_intersect_key($values, array_flip(self::FIELDS));
        foreach (['content', 'evidence', 'reviewed_content', 'context'] as $field) {
            if (isset($result[$field]) && is_string($result[$field])) {
                $result[$field] = self::decode($result[$field]);
            }
        }

        return self::privateFieldsRemoved($result);
    }

    /** Tokenize complete JSON strings before numbers; exact fractional values stay text. */
    public static function decode(string $json): mixed
    {
        $exact = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s',
            fn (array $token) => isset($token[1]) && strpbrk($token[1], '.eE') !== false ? json_encode($token[1], JSON_THROW_ON_ERROR) : $token[0], $json);

        return json_decode($exact, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    private static function privateFieldsRemoved(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array($key, ['storage_key', 'storage_root', 'source', 'path', 'password', 'remember_token', 'request_fingerprint', 'idempotency_scope'], true)) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = self::privateFieldsRemoved($value);
            }
        }

        return $values;
    }
}
