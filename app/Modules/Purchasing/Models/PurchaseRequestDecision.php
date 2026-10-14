<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestDecision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['purchase_request_id', 'decision', 'reason', 'decided_by_id'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
