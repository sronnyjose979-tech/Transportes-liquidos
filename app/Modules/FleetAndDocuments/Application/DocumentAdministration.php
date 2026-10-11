<?php

namespace App\Modules\FleetAndDocuments\Application;

use App\Models\User;
use App\Modules\FleetAndDocuments\Domain\DocumentOperationFailed;
use App\Modules\FleetAndDocuments\Infrastructure\PrivateDocumentStorage;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentAdministration
{
    public function __construct(private PrivateDocumentStorage $storage) {}

    private function authorize(User $actor, string $ability): void
    {
        $fresh = User::query()->find($actor->id);
        if ($fresh === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($fresh)->authorize($ability);
    }

    public function configure(User $actor, array $data, ?string $id = null): DocumentType
    {
        return DB::transaction(function () use ($actor, $data, $id): DocumentType {
            DB::select('SELECT pg_advisory_xact_lock(873210)');
            $this->authorize($actor, 'manage-document-types');
            $type = $id === null ? new DocumentType : DocumentType::query()->lockForUpdate()->findOrFail($id);
            $type->fill($data)->save();

            return $type;
        });
    }

    public function documents(): Builder
    {
        return Document::query()->with(['configurationSnapshot', 'uploadedBy:id,name'])->select('documents.*')
            ->selectRaw('EXISTS (SELECT 1 FROM current_fleet_documents WHERE document_id = documents.id) AS is_current');
    }

    public function register(User $actor, string $assetId, array $data, UploadedFile $file, ?string $renewedId = null): Document
    {
        $this->authorize($actor, 'manage-fleet-documents');
        FleetAsset::query()->findOrFail($assetId);
        $renewed = $renewedId === null ? null : Document::query()->where('fleet_asset_id', $assetId)->findOrFail($renewedId);
        $typeId = $renewed?->document_type_id ?? $data['document_type_id'];
        $id = (string) Str::uuid();

        try {
            return $this->storage->register($id, $file, function (string $key) use ($actor, $assetId, $data, $typeId, $id): Document {
                // All file IO completes before opening the PostgreSQL transaction.
                return DB::transaction(function () use ($actor, $assetId, $data, $typeId, $key, $id): Document {
                    DB::select('SELECT pg_advisory_xact_lock(873210)');
                    $this->authorize($actor, 'manage-fleet-documents');
                    $type = DocumentType::query()->lockForUpdate()->findOrFail($typeId);
                    FleetAsset::query()->findOrFail($assetId);
                    if ($type->tracks_expiration && empty($data['expires_on'])) {
                        throw ValidationException::withMessages(['expires_on' => 'La fecha de vencimiento es obligatoria para este tipo documental.']);
                    }
                    $document = new Document;
                    $document->forceFill(['id' => $id, 'fleet_asset_id' => $assetId, 'document_type_id' => $typeId,
                        'storage_key' => $key, 'uploaded_by_id' => $actor->id,
                        'issued_on' => $data['issued_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null]);
                    $document->save();

                    return $this->documents()->findOrFail($id);
                });
            });
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['23503', '23514'], true)) {
                throw ValidationException::withMessages(['document' => 'El documento no cumple las restricciones de integridad.']);
            }
            throw new DocumentOperationFailed('Fallo de persistencia documental.', previous: $exception);
        } catch (\Throwable $exception) {
            if ($exception instanceof AuthorizationException || $exception instanceof ValidationException
                || $exception instanceof ModelNotFoundException) {
                throw $exception;
            }
            throw new DocumentOperationFailed('Fallo de almacenamiento documental.', previous: $exception);
        }
    }
}
