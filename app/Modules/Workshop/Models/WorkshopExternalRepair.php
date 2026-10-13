<?php

namespace App\Modules\Workshop\Models;

use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkshopExternalRepair extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $table = 'workshop_external_repairs';

    protected $fillable = ['work_order_id', 'scope_task_id', 'supplier_id', 'description', 'performed_at', 'recorded_by_id', 'reason', 'root_record_id', 'previous_record_id', 'revision'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'revision' => 'integer', 'performed_at' => 'immutable_datetime'];
    }
}
