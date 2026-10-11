<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportAssignmentConflict extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['operation_id', 'asset_assignment_id', 'driver_assignment_id'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(TransportAssignmentOperation::class, 'operation_id');
    }

    public function assetAssignment(): BelongsTo
    {
        return $this->belongsTo(TripAssetAssignment::class, 'asset_assignment_id');
    }

    public function driverAssignment(): BelongsTo
    {
        return $this->belongsTo(DriverAssignment::class, 'driver_assignment_id');
    }
}
