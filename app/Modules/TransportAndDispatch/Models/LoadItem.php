<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\FleetAndDocuments\Models\Compartment;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use App\Modules\TransportAndDispatch\Models\Concerns\ValidatesTransportVolume;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LoadItem extends Model
{
    use HasUuids, PreservesTransportHistory, ValidatesTransportVolume;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['trip_load_id', 'compartment_id', 'loaded_liters', 'recorded_by_id', 'reason', 'evidence', 'occurred_at'];

    protected string $volumeAttribute = 'loaded_liters';

    protected function casts(): array
    {
        return ['loaded_liters' => 'decimal:2', 'capacity_liters_snapshot' => 'decimal:2', 'evidence' => 'array', 'occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function tripLoad(): BelongsTo
    {
        return $this->belongsTo(TripLoad::class, 'trip_load_id');
    }

    public function compartment(): BelongsTo
    {
        return $this->belongsTo(Compartment::class);
    }

    public function unloadItem(): HasOne
    {
        return $this->hasOne(UnloadItem::class, 'load_item_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
