<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TransportChange extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['transport_request_id', 'trip_id', 'action', 'before_values', 'after_values', 'from_version', 'to_version', 'actor_id', 'reason'];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'from_version' => 'integer', 'to_version' => 'integer', 'registered_at' => 'immutable_datetime'];
    }

    public function transportRequest(): BelongsTo
    {
        return $this->belongsTo(TransportRequest::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function application(): HasOne
    {
        return $this->hasOne(TransportAuthorizationApplication::class, 'change_id');
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(TripProductReview::class, 'request_change_id');
    }
}
