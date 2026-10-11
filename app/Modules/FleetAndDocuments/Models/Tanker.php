<?php

namespace App\Modules\FleetAndDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fleet_asset_id', 'has_physical_partitions'])]
class Tanker extends Model
{
    protected $primaryKey = 'fleet_asset_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['has_physical_partitions' => 'boolean'];
    }

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class, 'fleet_asset_id');
    }

    public function compartments(): HasMany
    {
        return $this->hasMany(Compartment::class, 'tanker_id', 'fleet_asset_id');
    }
}
