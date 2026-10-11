<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverRelease extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['assignment_id', 'operation_id'];

    protected function casts(): array
    {
        return ['ends_at' => 'immutable_datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DriverAssignment::class, 'assignment_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(TransportAssignmentOperation::class, 'operation_id');
    }
}
