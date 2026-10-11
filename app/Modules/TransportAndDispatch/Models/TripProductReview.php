<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripProductReview extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['trip_id', 'request_change_id', 'previous_product_id', 'proposed_product_id', 'result', 'reviewed_by_id', 'reason'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function requestChange(): BelongsTo
    {
        return $this->belongsTo(TransportChange::class, 'request_change_id');
    }

    public function previousProduct(): BelongsTo
    {
        return $this->belongsTo(LiquidProduct::class, 'previous_product_id');
    }

    public function proposedProduct(): BelongsTo
    {
        return $this->belongsTo(LiquidProduct::class, 'proposed_product_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
