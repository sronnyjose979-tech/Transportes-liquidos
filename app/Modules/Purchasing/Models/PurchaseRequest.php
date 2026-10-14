<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseRequest extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['supplier_id', 'reason', 'requested_by_id'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function decision(): HasOne
    {
        return $this->hasOne(PurchaseRequestDecision::class);
    }
}
