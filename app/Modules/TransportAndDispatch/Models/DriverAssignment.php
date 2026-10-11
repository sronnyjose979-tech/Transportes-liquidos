<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DriverAssignment extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['trip_id', 'employee_id', 'operation_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime'];
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereDoesntHave('release');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(TransportAssignmentOperation::class, 'operation_id');
    }

    public function release(): HasOne
    {
        return $this->hasOne(DriverRelease::class, 'assignment_id');
    }
}
