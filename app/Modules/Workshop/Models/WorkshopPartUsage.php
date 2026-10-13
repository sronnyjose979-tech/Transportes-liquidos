<?php

namespace App\Modules\Workshop\Models;

use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkshopPartUsage extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $table = 'workshop_part_usages';

    protected $fillable = ['delivery_id', 'quantity', 'confirmed_by_id', 'mechanic_assignment_id', 'occurred_at', 'reason', 'root_record_id', 'previous_usage_id', 'revision'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'revision' => 'integer', 'quantity' => 'decimal:6', 'occurred_at' => 'immutable_datetime'];
    }
}
