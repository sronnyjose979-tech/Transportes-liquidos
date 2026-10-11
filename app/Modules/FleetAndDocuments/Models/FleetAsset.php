<?php

namespace App\Modules\FleetAndDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['code', 'type', 'service_status'])]
class FleetAsset extends Model
{
    use HasUuids;

    public $timestamps = false;

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function tractorUnit(): HasOne
    {
        return $this->hasOne(TractorUnit::class, 'fleet_asset_id');
    }

    public function tanker(): HasOne
    {
        return $this->hasOne(Tanker::class, 'fleet_asset_id');
    }
}
