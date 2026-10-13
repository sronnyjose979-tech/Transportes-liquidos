<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\TripAssetAssignment;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopWarning extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['admission_id', 'trip_asset_assignment_id', 'work_order_id', 'code', 'context', 'captured_by_id', 'registered_at'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'context' => 'array'];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(WorkshopAdmission::class, 'admission_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TripAssetAssignment::class, 'trip_asset_assignment_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkshopWorkOrder::class, 'work_order_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_id');
    }
}
