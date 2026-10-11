<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Modules\TransportAndDispatch\Models\Concerns\PreservesTransportHistory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripWarning extends Model
{
    use HasUuids, PreservesTransportHistory;

    public $timestamps = false;

    protected $fillable = ['trip_id', 'code', 'evidence', 'closure_version'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'closure_version' => 'integer', 'captured_at' => 'immutable_datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(TripWarningResolution::class, 'warning_id');
    }
}
