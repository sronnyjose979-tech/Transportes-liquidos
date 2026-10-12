<?php

namespace App\Modules\TransportAndDispatch\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Trip extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['transport_request_id', 'liquid_product_id', 'product_name_snapshot', 'planning_status', 'execution_status', 'administrative_status', 'scheduled_start_at', 'scheduled_end_at', 'officially_started_at', 'officially_completed_at', 'closed_at', 'cancelled_at', 'started_by_id', 'completed_by_id', 'closed_by_id', 'cancelled_by_id', 'cancellation_reason', 'registered_by_id', 'version'];

    protected function casts(): array
    {
        return ['scheduled_start_at' => 'immutable_datetime', 'scheduled_end_at' => 'immutable_datetime', 'officially_started_at' => 'immutable_datetime', 'officially_completed_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function transportRequest(): BelongsTo
    {
        return $this->belongsTo(TransportRequest::class);
    }

    public function liquidProduct(): BelongsTo
    {
        return $this->belongsTo(LiquidProduct::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TripEvent::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(TransportChange::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(TripProductReview::class);
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(TripWarning::class);
    }

    public function authorizationRequests(): HasMany
    {
        return $this->hasMany(TransportAuthorizationRequest::class);
    }

    public function assetAssignments(): HasMany
    {
        return $this->hasMany(TripAssetAssignment::class);
    }

    public function driverAssignments(): HasMany
    {
        return $this->hasMany(DriverAssignment::class);
    }

    public function assignmentOperations(): HasMany
    {
        return $this->hasMany(TransportAssignmentOperation::class);
    }

    public function tripLoad(): HasOne
    {
        return $this->hasOne(TripLoad::class);
    }

    public function unload(): HasOne
    {
        return $this->hasOne(TripUnload::class);
    }

    public function odometerReadings(): HasMany
    {
        return $this->hasMany(TripOdometerReading::class);
    }
}
