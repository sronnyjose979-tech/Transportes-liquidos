<?php

namespace App\Modules\FleetAndDocuments\Models;

use App\Models\User;
use App\Modules\FleetAndDocuments\Domain\DocumentValidity;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_type_id', 'fleet_asset_id', 'storage_key', 'issued_on', 'expires_on', 'uploaded_by_id'])]
class Document extends Model
{
    use HasUuids;

    public const ALLOWED_FILE_EXTENSIONS = ['pdf', 'jpg', 'png'];

    public const MAX_FILE_SIZE_BYTES = 10_000_000;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['issued_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'registered_at' => 'immutable_datetime'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(FleetAsset::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    private static function localDate(?DateTimeInterface $at): CarbonImmutable
    {
        return ($at === null ? CarbonImmutable::now('America/Costa_Rica') : CarbonImmutable::instance($at)->setTimezone('America/Costa_Rica'))->startOfDay();
    }

    public function validity(?DateTimeInterface $at = null): DocumentValidity
    {
        if (! $this->documentType->tracks_expiration) {
            return DocumentValidity::NoExpiration;
        }
        $today = self::localDate($at)->toDateString();
        $expiration = $this->expires_on->toDateString();
        if ($expiration < $today) {
            return DocumentValidity::Expired;
        }

        return $expiration <= self::localDate($at)->addDays(15)->toDateString()
            ? DocumentValidity::ExpiringSoon : DocumentValidity::Valid;
    }

    public function scopeWithValidity(Builder $query, DocumentValidity $validity, ?DateTimeInterface $at = null): Builder
    {
        $today = self::localDate($at);
        $query->whereHas('documentType', fn (Builder $types) => $types->where('tracks_expiration', $validity !== DocumentValidity::NoExpiration));

        return match ($validity) {
            DocumentValidity::NoExpiration => $query,
            DocumentValidity::Expired => $query->where('expires_on', '<', $today->toDateString()),
            DocumentValidity::ExpiringSoon => $query->whereBetween('expires_on', [$today->toDateString(), $today->addDays(15)->toDateString()]),
            DocumentValidity::Valid => $query->where('expires_on', '>', $today->addDays(15)->toDateString()),
        };
    }
}
