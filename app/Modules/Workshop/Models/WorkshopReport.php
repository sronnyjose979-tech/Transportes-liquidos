<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\TransportAndDispatch\Models\TripEvent;
use App\Modules\Workshop\Models\Concerns\PreservesWorkshopHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopReport extends Model
{
    use HasUuids, PreservesWorkshopHistory;

    public $timestamps = false;

    protected $fillable = ['fleet_asset_id', 'trip_event_id', 'description', 'reporter_id', 'recorded_by_id', 'occurred_at', 'reason', 'root_record_id', 'previous_report_id', 'revision'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class, 'fleet_asset_id');
    }

    public function tripEvent(): BelongsTo
    {
        return $this->belongsTo(TripEvent::class, 'trip_event_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function rootRecord(): BelongsTo
    {
        return $this->belongsTo(WorkshopReport::class, 'root_record_id');
    }

    public function previousReport(): BelongsTo
    {
        return $this->belongsTo(WorkshopReport::class, 'previous_report_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WorkshopReport::class, 'root_record_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(WorkshopReportLink::class, 'report_id');
    }
}
