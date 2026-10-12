<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportAuthorizationApplication extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['decision_id', 'change_id', 'operation_id', 'validation_type', 'applied_by_id'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(TransportAuthorizationDecision::class, 'decision_id');
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(TransportChange::class, 'change_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(TransportAssignmentOperation::class, 'operation_id');
    }
}
