<?php

namespace App\Modules\FleetAndDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['name', 'tracks_expiration', 'required_for_tractor_units', 'required_for_tankers'])]
class DocumentType extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['tracks_expiration' => 'boolean', 'required_for_tractor_units' => 'boolean', 'required_for_tankers' => 'boolean'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function scopeRequiredFor(Builder $query, string $assetType): Builder
    {
        $column = match ($assetType) {
            'tractor_unit' => 'required_for_tractor_units',
            'tanker' => 'required_for_tankers',
            default => throw new InvalidArgumentException('Tipo de activo desconocido.'),
        };

        return $query->where($column, true);
    }

    // Current requirements are forward-looking; absence is distinct from expiration.
    public function scopeMissingFor(Builder $query, FleetAsset $asset): Builder
    {
        return $query->requiredFor($asset->type)->whereDoesntHave('documents', fn (Builder $documents) => $documents->current()->where('fleet_asset_id', $asset->id));
    }
}
