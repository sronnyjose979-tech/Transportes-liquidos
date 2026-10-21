<?php

namespace App\Modules\FleetAndDocuments\Application;

use App\Models\User;
use App\Modules\FleetAndDocuments\Domain\DocumentOperationFailed;
use App\Modules\FleetAndDocuments\Domain\DocumentUploadConflict;
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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $requestKey = strtolower($data['request_key'] ?? '');
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'Se requiere una clave UUID por operación.']);
        }
        $id = (string) Str::uuid();

        try {
            $fileHash = hash_file('sha256', $file->getRealPath());
            if ($fileHash === false) {
                throw new \RuntimeException('No se pudo verificar el archivo pendiente.');
            }
            $fingerprint = hash('sha256', json_encode([strtolower($assetId), $renewedId === null ? null : strtolower($renewedId),
                strtolower($typeId), $data['issued_on'] ?? null, $data['expires_on'] ?? null,
                $fileHash, $file->getSize(), $file->getMimeType(), strtolower($file->getClientOriginalExtension())], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($document = $this->replay($actor, $requestKey, $fingerprint)) {
                return $document;
            }

            return $this->storage->register($id, $file, function (string $key) use ($actor, $assetId, $data, $typeId, $id, $requestKey, $fingerprint, $renewedId): Document {
                // All file IO completes before opening the PostgreSQL transaction.
                return DB::transaction(function () use ($actor, $assetId, $data, $typeId, $key, $id, $requestKey, $fingerprint, $renewedId): Document {
                    DB::select('SELECT pg_advisory_xact_lock(873210)');
                    $this->authorize($actor, 'manage-fleet-documents');
                    if ($document = $this->replay($actor, $requestKey, $fingerprint)) {
                        return $document;
                    }
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
                    DB::table('document_upload_operations')->insert(['actor_id' => $actor->id, 'request_key' => $requestKey,
                        'fleet_asset_id' => $assetId, 'renewed_document_id' => $renewedId,
                        'document_id' => $id, 'fingerprint' => $fingerprint]);

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
                || $exception instanceof ModelNotFoundException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            throw new DocumentOperationFailed('Fallo de almacenamiento documental.', previous: $exception);
        }
    }

    private function replay(User $actor, string $requestKey, string $fingerprint): ?Document
    {
        $receipt = DB::table('document_upload_operations')->where('actor_id', $actor->id)->where('request_key', $requestKey)->first();
        if ($receipt === null) {
            return null;
        }
        if (! hash_equals($receipt->fingerprint, $fingerprint)) {
            throw new DocumentUploadConflict;
        }

        $this->authorize($actor, 'manage-fleet-documents');

        return $this->documents()->findOrFail($receipt->document_id);
    }

    public function uploadResult(User $actor, string $assetId, string $requestKey, ?string $fingerprint = null): Document
    {
        $this->authorize($actor, 'manage-fleet-documents');
        $receipt = DB::table('document_upload_operations')->where('actor_id', $actor->id)
            ->where('request_key', $requestKey)->where('fleet_asset_id', $assetId)->first();
        abort_if($receipt === null, 404);
        if ($fingerprint !== null && ! hash_equals($receipt->fingerprint, $fingerprint)) {
            throw new DocumentUploadConflict;
        }

        return $this->documents()->findOrFail($receipt->document_id);
    }
}
