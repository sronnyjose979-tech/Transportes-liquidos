<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TripOdometerReading extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['trip_id', 'tractor_assignment_id', 'reading_type', 'reading_value', 'previous_reading_id', 'revision', 'recorded_by_id', 'reason', 'evidence', 'occurred_at'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'evidence' => 'array', 'occurred_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereDoesntHave('successor');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function tractorAssignment(): BelongsTo
    {
        return $this->belongsTo(TripAssetAssignment::class, 'tractor_assignment_id');
    }

    public function previousReading(): BelongsTo
    {
        return $this->belongsTo(TripOdometerReading::class, 'previous_reading_id');
    }

    public function successor(): HasOne
    {
        return $this->hasOne(TripOdometerReading::class, 'previous_reading_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
