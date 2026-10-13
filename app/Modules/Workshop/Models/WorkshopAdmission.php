<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkshopAdmission extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['fleet_asset_id', 'kind', 'admitted_at', 'admitted_by_id', 'reason'];

    protected function casts(): array
    {
        return ['admitted_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class, 'fleet_asset_id');
    }

    public function admittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitted_by_id');
    }

    public function orderLinks(): HasMany
    {
        return $this->hasMany(WorkshopOrderAdmissionLink::class, 'admission_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(WorkshopOperation::class, 'admission_id');
    }

    public function exit(): HasOne
    {
        return $this->hasOne(WorkshopAdmissionExit::class, 'admission_id');
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(WorkshopWarning::class, 'admission_id');
    }
}
