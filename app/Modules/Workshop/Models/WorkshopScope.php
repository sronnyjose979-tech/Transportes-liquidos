<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopScope extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'diagnosis_id', 'scope_kind', 'proposed_by_id', 'description', 'root_record_id', 'previous_scope_id', 'revision'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkshopWorkOrder::class, 'work_order_id');
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(WorkshopDiagnosis::class, 'diagnosis_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function rootRecord(): BelongsTo
    {
        return $this->belongsTo(WorkshopScope::class, 'root_record_id');
    }

    public function previousScope(): BelongsTo
    {
        return $this->belongsTo(WorkshopScope::class, 'previous_scope_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkshopScopeTask::class, 'scope_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(WorkshopScopePart::class, 'scope_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(WorkshopAuthorizationRequest::class, 'scope_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WorkshopScope::class, 'root_record_id');
    }
}
