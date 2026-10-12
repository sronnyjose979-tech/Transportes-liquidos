<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripUnload extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['trip_id', 'trip_load_id', 'operation_site_id', 'recorded_by_id', 'reason', 'evidence', 'occurred_at'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function tripLoad(): BelongsTo
    {
        return $this->belongsTo(TripLoad::class, 'trip_load_id');
    }

    public function operationSite(): BelongsTo
    {
        return $this->belongsTo(OperationSite::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(UnloadItem::class, 'trip_unload_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
