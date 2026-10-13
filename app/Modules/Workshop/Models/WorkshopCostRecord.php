<?php

namespace App\Modules\Workshop\Models;

use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkshopCostRecord extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $table = 'workshop_cost_records';

    protected $fillable = ['work_order_id', 'scope_task_id', 'scope_part_id', 'usage_id', 'external_repair_id', 'cost_kind', 'basis', 'amount', 'currency', 'source_attachment_id', 'covered_by_cost_id', 'description', 'actor_id', 'occurred_at', 'reason', 'root_record_id', 'previous_cost_id', 'revision'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'revision' => 'integer', 'amount' => 'decimal:6', 'occurred_at' => 'immutable_datetime'];
    }
}
