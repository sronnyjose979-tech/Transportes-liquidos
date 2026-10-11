<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripWarningResolution extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['warning_id', 'action', 'evidence', 'actor_id', 'reason'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'registered_at' => 'immutable_datetime'];
    }

    public function warning(): BelongsTo
    {
        return $this->belongsTo(TripWarning::class, 'warning_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
