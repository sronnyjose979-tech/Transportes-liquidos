<?php

namespace App\Modules\Workshop\Models;

use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkshopPartDelivery extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $table = 'workshop_part_deliveries';

    protected $fillable = ['scope_part_id', 'quantity', 'delivered_to_assignment_id', 'recorded_by_id', 'occurred_at', 'reason', 'root_record_id', 'previous_delivery_id', 'revision'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'revision' => 'integer', 'quantity' => 'decimal:6', 'occurred_at' => 'immutable_datetime'];
    }
}
