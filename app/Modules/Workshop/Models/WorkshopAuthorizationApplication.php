<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopAuthorizationApplication extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['decision_id', 'operation_id', 'applied_by_id'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(WorkshopAuthorizationDecision::class, 'decision_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkshopOperation::class, 'operation_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }
}
