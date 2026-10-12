<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopDiagnosis extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'mechanic_assignment_id', 'problem', 'probable_cause', 'proposed_repair', 'actor_id', 'occurred_at', 'reason', 'root_record_id', 'previous_diagnosis_id', 'revision'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkshopWorkOrder::class, 'work_order_id');
    }

    public function mechanicAssignment(): BelongsTo
    {
        return $this->belongsTo(WorkshopMechanicAssignment::class, 'mechanic_assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function rootRecord(): BelongsTo
    {
        return $this->belongsTo(WorkshopDiagnosis::class, 'root_record_id');
    }

    public function previousDiagnosis(): BelongsTo
    {
        return $this->belongsTo(WorkshopDiagnosis::class, 'previous_diagnosis_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WorkshopDiagnosis::class, 'root_record_id');
    }
}
