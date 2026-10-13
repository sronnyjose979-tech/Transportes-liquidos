<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkshopAuthorizationRequest extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'admission_id', 'scope_id', 'type', 'previous_request_id', 'content', 'target_version', 'requested_by_id', 'reason'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'content' => 'array', 'target_version' => 'integer'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkshopWorkOrder::class, 'work_order_id');
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(WorkshopAdmission::class, 'admission_id');
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(WorkshopScope::class, 'scope_id');
    }

    public function previousRequest(): BelongsTo
    {
        return $this->belongsTo(WorkshopAuthorizationRequest::class, 'previous_request_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(WorkshopAuthorizationDecision::class, 'request_id');
    }
}
