<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopOperation extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'admission_id', 'report_id', 'fleet_asset_id', 'operation_type', 'before_values', 'after_values', 'from_version', 'to_version', 'actor_id', 'occurred_at', 'reason', 'request_key', 'request_fingerprint', 'idempotency_scope', 'confirmed_result', 'report_link_id', 'mechanic_assignment_id', 'mechanic_release_id', 'diagnosis_id', 'order_admission_link_id', 'reservation_operation_id'];

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
}
