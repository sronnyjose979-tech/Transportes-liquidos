<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use App\Modules\TransportAndDispatch\Models\Concerns\ValidatesTransportVolume;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnloadItem extends Model
{
    use HasUuids, PreservesTransportHistory, ValidatesTransportVolume;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['trip_unload_id', 'load_item_id', 'delivered_liters', 'recorded_by_id', 'reason', 'evidence', 'occurred_at'];

    protected string $volumeAttribute = 'delivered_liters';

    protected function casts(): array
    {
        return ['delivered_liters' => 'decimal:2', 'evidence' => 'array', 'occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function unload(): BelongsTo
    {
        return $this->belongsTo(TripUnload::class, 'trip_unload_id');
    }

    public function loadItem(): BelongsTo
    {
        return $this->belongsTo(LoadItem::class, 'load_item_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
