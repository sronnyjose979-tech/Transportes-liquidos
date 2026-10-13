<?php

namespace App\Modules\Workshop\Models;

use App\Modules\Inventory\Models\SparePart;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopScopePart extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['scope_id', 'task_id', 'spare_part_id', 'line_number', 'quantity', 'unit_snapshot', 'description_snapshot'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'line_number' => 'integer', 'quantity' => 'decimal:6'];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(WorkshopScope::class, 'scope_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkshopScopeTask::class, 'task_id');
    }

    public function sparePart(): BelongsTo
    {
        return $this->belongsTo(SparePart::class, 'spare_part_id');
    }
}
