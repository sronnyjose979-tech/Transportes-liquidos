<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TransportAuthorizationDecision extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['authorization_request_id', 'decision', 'decided_by_id', 'reason'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    public function authorizationRequest(): BelongsTo
    {
        return $this->belongsTo(TransportAuthorizationRequest::class, 'authorization_request_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function application(): HasOne
    {
        return $this->hasOne(TransportAuthorizationApplication::class, 'decision_id');
    }
}
