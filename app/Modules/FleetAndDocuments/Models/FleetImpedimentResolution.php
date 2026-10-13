<?php

namespace App\Modules\FleetAndDocuments\Models;

use App\Models\User;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use App\Modules\Workshop\Models\WorkshopOperation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetImpedimentResolution extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['impediment_id', 'operation_id', 'resolved_by_id', 'resolved_at', 'registered_at', 'reason', 'evidence'];

    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'evidence' => 'array'];
    }

    public function impediment(): BelongsTo
    {
        return $this->belongsTo(FleetOperatingImpediment::class, 'impediment_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(WorkshopOperation::class, 'operation_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
