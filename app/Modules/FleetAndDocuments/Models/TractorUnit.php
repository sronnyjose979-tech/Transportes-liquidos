<?php

namespace App\Modules\FleetAndDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fleet_asset_id'])]
class TractorUnit extends Model
{
    protected $primaryKey = 'fleet_asset_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class, 'fleet_asset_id');
    }
}
