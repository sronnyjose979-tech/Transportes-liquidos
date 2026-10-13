<?php

namespace App\Modules\FleetAndDocuments\Models;

use App\Models\User;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use App\Modules\Workshop\Models\WorkshopAdmission;
use App\Modules\Workshop\Models\WorkshopWorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FleetOperatingImpediment extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['fleet_asset_id', 'kind', 'admission_id', 'work_order_id', 'effect', 'description', 'actor_id', 'occurred_at', 'registered_at', 'reason'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class, 'fleet_asset_id');
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(WorkshopAdmission::class, 'admission_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkshopWorkOrder::class, 'work_order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function resolution(): HasOne
    {
        return $this->hasOne(FleetImpedimentResolution::class, 'impediment_id');
    }
}
