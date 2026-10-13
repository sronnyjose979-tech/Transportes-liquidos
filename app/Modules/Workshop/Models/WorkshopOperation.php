<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\FleetOperatingImpediment;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopOperation extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'admission_id', 'report_id', 'fleet_asset_id', 'operation_type', 'before_values', 'after_values', 'from_version', 'to_version', 'actor_id', 'occurred_at', 'reason', 'request_key', 'request_fingerprint', 'idempotency_scope', 'confirmed_result', 'report_link_id', 'mechanic_assignment_id', 'mechanic_release_id', 'diagnosis_id', 'order_admission_link_id', 'reservation_operation_id', 'scope_id', 'authorization_request_id', 'authorization_decision_id', 'impediment_id', 'warning_id', 'activity_id', 'time_event_id', 'delivery_id', 'usage_id', 'external_repair_id', 'attachment_id', 'cost_record_id', 'material_need_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'from_version' => 'integer', 'to_version' => 'integer', 'before_values' => 'array', 'after_values' => 'array', 'confirmed_result' => 'array'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkshopWorkOrder::class, 'work_order_id');
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(WorkshopAdmission::class, 'admission_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WorkshopReport::class, 'report_id');
    }

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class, 'fleet_asset_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function reportLink(): BelongsTo
    {
        return $this->belongsTo(WorkshopReportLink::class, 'report_link_id');
    }

    public function mechanicAssignment(): BelongsTo
    {
        return $this->belongsTo(WorkshopMechanicAssignment::class, 'mechanic_assignment_id');
    }

    public function mechanicRelease(): BelongsTo
    {
        return $this->belongsTo(WorkshopMechanicRelease::class, 'mechanic_release_id');
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(WorkshopDiagnosis::class, 'diagnosis_id');
    }

    public function orderAdmissionLink(): BelongsTo
    {
        return $this->belongsTo(WorkshopOrderAdmissionLink::class, 'order_admission_link_id');
    }

    public function reservationOperation(): BelongsTo
    {
        return $this->belongsTo(WorkshopOperation::class, 'reservation_operation_id');
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(WorkshopScope::class, 'scope_id');
    }

    public function authorizationRequest(): BelongsTo
    {
        return $this->belongsTo(WorkshopAuthorizationRequest::class, 'authorization_request_id');
    }

    public function authorizationDecision(): BelongsTo
    {
        return $this->belongsTo(WorkshopAuthorizationDecision::class, 'authorization_decision_id');
    }

    public function impediment(): BelongsTo
    {
        return $this->belongsTo(FleetOperatingImpediment::class, 'impediment_id');
    }

    public function warning(): BelongsTo
    {
        return $this->belongsTo(WorkshopWarning::class, 'warning_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(WorkshopActivity::class, 'activity_id');
    }

    public function timeEvent(): BelongsTo
    {
        return $this->belongsTo(WorkshopTimeEvent::class, 'time_event_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WorkshopPartDelivery::class, 'delivery_id');
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(WorkshopPartUsage::class, 'usage_id');
    }

    public function externalRepair(): BelongsTo
    {
        return $this->belongsTo(WorkshopExternalRepair::class, 'external_repair_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(WorkshopAttachment::class, 'attachment_id');
    }

    public function costRecord(): BelongsTo
    {
        return $this->belongsTo(WorkshopCostRecord::class, 'cost_record_id');
    }

    public function materialNeed(): BelongsTo
    {
        return $this->belongsTo(WorkshopMaterialNeed::class, 'material_need_id');
    }
}
