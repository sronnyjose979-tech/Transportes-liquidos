<?php

namespace App\Modules\FleetAndDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tanker_id', 'code', 'capacity_liters', 'is_active'])]
class Compartment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['capacity_liters' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function scopeWithTankerCapacity(Builder $query): void
    {
        // Total and details share one snapshot; NUMERIC remains exact as text.
        $query->select('compartments.*')->selectRaw(
            'SUM(capacity_liters) OVER (PARTITION BY tanker_id)::text AS tanker_total_capacity_liters'
        );
    }

    public function tanker(): BelongsTo
    {
        return $this->belongsTo(Tanker::class, 'tanker_id', 'fleet_asset_id');
    }
}
