<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TripLoad extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['trip_id', 'tanker_assignment_id', 'operation_site_id', 'recorded_by_id', 'reason', 'evidence', 'occurred_at'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function tankerAssignment(): BelongsTo
    {
        return $this->belongsTo(TripAssetAssignment::class, 'tanker_assignment_id');
    }

    public function operationSite(): BelongsTo
    {
        return $this->belongsTo(OperationSite::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoadItem::class, 'trip_load_id');
    }

    public function unload(): HasOne
    {
        return $this->hasOne(TripUnload::class, 'trip_load_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
