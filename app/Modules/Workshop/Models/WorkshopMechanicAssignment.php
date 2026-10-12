<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkshopMechanicAssignment extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'employee_id', 'starts_at', 'assigned_by_id', 'reason'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkshopWorkOrder::class, 'work_order_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function release(): HasOne
    {
        return $this->hasOne(WorkshopMechanicRelease::class, 'assignment_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(WorkshopDiagnosis::class, 'mechanic_assignment_id');
    }
}
