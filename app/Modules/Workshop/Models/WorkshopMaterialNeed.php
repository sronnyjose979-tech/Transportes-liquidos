<?php

namespace App\Modules\Workshop\Models;

use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkshopMaterialNeed extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $table = 'workshop_material_needs';

    protected $fillable = ['work_order_id', 'scope_part_id', 'spare_part_id', 'quantity', 'description', 'requested_by_id', 'occurred_at', 'reason', 'root_record_id', 'previous_need_id', 'revision'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'revision' => 'integer', 'quantity' => 'decimal:6', 'occurred_at' => 'immutable_datetime'];
    }
}
