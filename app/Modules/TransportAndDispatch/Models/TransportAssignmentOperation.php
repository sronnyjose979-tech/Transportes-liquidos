<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TransportAssignmentOperation extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['trip_id', 'action', 'resource_kind', 'payload', 'target_version', 'actor_id', 'reason'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'target_version' => 'integer', 'registered_at' => 'immutable_datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function assetAssignment(): HasOne
    {
        return $this->hasOne(TripAssetAssignment::class, 'operation_id');
    }

    public function driverAssignment(): HasOne
    {
        return $this->hasOne(DriverAssignment::class, 'operation_id');
    }

    public function assetRelease(): HasOne
    {
        return $this->hasOne(TripAssetRelease::class, 'operation_id');
    }

    public function driverRelease(): HasOne
    {
        return $this->hasOne(DriverRelease::class, 'operation_id');
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(TransportAssignmentConflict::class, 'operation_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(TransportAuthorizationApplication::class, 'operation_id');
    }
}
