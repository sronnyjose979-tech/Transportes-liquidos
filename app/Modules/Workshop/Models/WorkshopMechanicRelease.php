<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopMechanicRelease extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['assignment_id', 'ends_at', 'recorded_by_id', 'reason'];

    protected function casts(): array
    {
        return ['ends_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(WorkshopMechanicAssignment::class, 'assignment_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
