<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopOrderAdmissionLink extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'admission_id', 'fleet_asset_id', 'linked_by_id', 'reason', 'operation_id'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkshopWorkOrder::class, 'work_order_id');
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(WorkshopAdmission::class, 'admission_id');
    }

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class, 'fleet_asset_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkshopOperation::class, 'operation_id');
    }
}
