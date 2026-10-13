<?php

namespace App\Modules\Workshop\Models;

use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkshopTimeEvent extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['work_order_id', 'mechanic_assignment_id', 'event_type', 'previous_event_id', 'actor_id', 'occurred_at', 'reason', 'root_record_id', 'revision'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'revision' => 'integer'];
    }
}
