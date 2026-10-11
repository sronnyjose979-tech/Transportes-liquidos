<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripEvent extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['trip_id', 'type', 'information', 'created_by_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['information' => 'array', 'occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
