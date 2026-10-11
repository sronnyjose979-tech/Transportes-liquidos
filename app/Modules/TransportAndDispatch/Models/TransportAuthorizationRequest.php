<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TransportAuthorizationRequest extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['transport_request_id', 'trip_id', 'type', 'content', 'target_version', 'requested_by_id', 'reason'];

    protected function casts(): array
    {
        return ['content' => 'array', 'target_version' => 'integer', 'registered_at' => 'immutable_datetime'];
    }

    public function transportRequest(): BelongsTo
    {
        return $this->belongsTo(TransportRequest::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(TransportAuthorizationDecision::class, 'authorization_request_id');
    }
}
