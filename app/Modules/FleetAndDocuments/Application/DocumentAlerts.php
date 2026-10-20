<?php

namespace App\Modules\FleetAndDocuments\Application;

use App\Models\User;
use App\Modules\FleetAndDocuments\Domain\DocumentValidity;
use App\Modules\FleetAndDocuments\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DocumentAlerts
{
    public function query(array $filters = [], ?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now('America/Costa_Rica');
        $query = Document::query()->current()->with(['configurationSnapshot', 'fleetAsset'])
            ->leftJoin('document_alert_reviews as review', 'review.document_id', '=', 'documents.id')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'review.reviewed_by_id')
            ->select('documents.id', 'documents.fleet_asset_id', 'documents.document_type_id', 'documents.expires_on', 'documents.registered_at', 'review.reviewed_at', 'review.reviewed_by_id', 'review.validity_at_review', 'reviewer.name as reviewer_name');
        if (isset($filters['status'])) {
            $query->withValidity(DocumentValidity::from($filters['status']), $at);
        } else {
            $query->where(fn ($q) => $q->where(fn ($expired) => $expired->withValidity(DocumentValidity::Expired, $at))
                ->orWhere(fn ($soon) => $soon->withValidity(DocumentValidity::ExpiringSoon, $at)));
        }
        foreach (['document_type_id', 'fleet_asset_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where('documents.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['asset_type'])) {
            $query->whereHas('fleetAsset', fn ($q) => $q->where('type', $filters['asset_type']));
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $op) {
            if (isset($filters[$field])) {
                $query->where('documents.expires_on', $op, $filters[$field]);
            }
        }
        if (($filters['attention'] ?? null) === 'reviewed') {
            $query->whereNotNull('review.document_id');
        } elseif (($filters['attention'] ?? null) === 'unreviewed') {
            $query->whereNull('review.document_id');
        }

        return $query;
    }

    public function summary(): array
    {
        $at = CarbonImmutable::now('America/Costa_Rica');
        $query = DB::query();
        foreach (['expired' => ['status' => 'expired'], 'expiring_soon' => ['status' => 'expiring_soon'], 'unreviewed' => ['attention' => 'unreviewed']] as $key => $filters) {
            $query->selectSub($this->query($filters, $at)->toBase()->reorder()->select(DB::raw('count(*)')), $key);
        }
        $counts = $query->first();

        return ['expired' => (int) $counts->expired, 'expiring_soon' => (int) $counts->expiring_soon,
            'unreviewed' => (int) $counts->unreviewed,
            'as_of' => $at->toDateString(), 'timezone' => 'America/Costa_Rica', 'personnel_supported' => false];
    }

    public function review(User $actor, string $document): object
    {
        return DB::transaction(function () use ($actor, $document): object {
            DB::select('SELECT pg_advisory_xact_lock(873210)');
            $fresh = User::query()->lockForUpdate()->findOrFail($actor->id);
            DB::table('user_roles')->where('user_id', $fresh->id)->lockForUpdate()->get();
            Gate::forUser($fresh)->authorize('manage-fleet-documents');
            if ($previous = DB::table('document_alert_reviews')->where('document_id', $document)->first()) {
                return $previous;
            }
            $record = Document::query()->with('configurationSnapshot')->findOrFail($document);
            if (! $record->current()->where('documents.id', $document)->exists()) {
                throw new ConflictHttpException('El documento fue reemplazado. Consulta las alertas actuales.');
            }
            $validity = $record->validity();
            if (! in_array($validity, [DocumentValidity::Expired, DocumentValidity::ExpiringSoon], true)) {
                throw new ConflictHttpException('El documento no tiene un vencimiento pendiente de atención.');
            }
            DB::table('document_alert_reviews')->insert(['id' => (string) Str::uuid(), 'document_id' => $document,
                'reviewed_by_id' => $fresh->id, 'validity_at_review' => $validity->value]);

            return DB::table('document_alert_reviews')->where('document_id', $document)->first();
        });
    }
}
