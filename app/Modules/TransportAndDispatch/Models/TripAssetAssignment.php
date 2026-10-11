<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TripAssetAssignment extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['trip_id', 'fleet_asset_id', 'operation_id', 'asset_type'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime'];
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereDoesntHave('release');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(TransportAssignmentOperation::class, 'operation_id');
    }

    public function release(): HasOne
    {
        return $this->hasOne(TripAssetRelease::class, 'assignment_id');
    }
}
