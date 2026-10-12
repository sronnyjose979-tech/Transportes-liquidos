<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopReportLink extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['report_id', 'work_order_id', 'linked_by_id', 'reason'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WorkshopReport::class, 'report_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkshopWorkOrder::class, 'work_order_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_id');
    }
}
