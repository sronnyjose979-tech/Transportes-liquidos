<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopWorkOrder extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['fleet_asset_id', 'kind', 'description', 'priority', 'status', 'created_at', 'created_by_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class, 'fleet_asset_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function reportLinks(): HasMany
    {
        return $this->hasMany(WorkshopReportLink::class, 'work_order_id');
    }

    public function mechanicAssignments(): HasMany
    {
        return $this->hasMany(WorkshopMechanicAssignment::class, 'work_order_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(WorkshopDiagnosis::class, 'work_order_id');
    }

    public function admissionLinks(): HasMany
    {
        return $this->hasMany(WorkshopOrderAdmissionLink::class, 'work_order_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(WorkshopOperation::class, 'work_order_id');
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(WorkshopScope::class, 'work_order_id');
    }

    public function authorizationRequests(): HasMany
    {
        return $this->hasMany(WorkshopAuthorizationRequest::class, 'work_order_id');
    }

    public function timeEvents(): HasMany
    {
        return $this->hasMany(WorkshopTimeEvent::class, 'work_order_id');
    }

    public function externalRepairs(): HasMany
    {
        return $this->hasMany(WorkshopExternalRepair::class, 'work_order_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkshopAttachment::class, 'work_order_id');
    }

    public function costRecords(): HasMany
    {
        return $this->hasMany(WorkshopCostRecord::class, 'work_order_id');
    }

    public function materialNeeds(): HasMany
    {
        return $this->hasMany(WorkshopMaterialNeed::class, 'work_order_id');
    }
}
