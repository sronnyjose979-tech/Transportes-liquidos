<?php

namespace App\Modules\FleetAndDocuments\Infrastructure;

use App\Modules\FleetAndDocuments\Domain\DocumentOperationFailed;
use App\Modules\FleetAndDocuments\Models\Document;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PrivateDocumentStorage
{
    public function disk(): FilesystemAdapter
    {
        return Storage::disk('fleet_documents');
    }

    private function synchronized(Closure $operation): mixed
    {
        $disk = $this->disk();
        $lock = @fopen($disk->path('.coordination.lock'), 'c');
        if ($lock === false) {
            throw new RuntimeException('No se pudo abrir el coordinador del almacenamiento privado.');
        }
        if (! flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('No se pudo coordinar el almacenamiento privado.');
        }
        try {
            // Directory creation must also be serialized on a fresh private disk.
            $disk->makeDirectory('.pending');

            return $operation($disk);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function register(string $id, UploadedFile $file, Closure $persist): Document
    {
        return $this->synchronized(function (FilesystemAdapter $disk) use ($id, $file, $persist): Document {
            $key = 'files/'.$id.'.'.strtolower($file->getClientOriginalExtension());
            $marker = '.pending/'.$id.'.json';
            if ($disk->exists($key) || $disk->exists($marker)) {
                throw new RuntimeException('La referencia de carga ya existe.');
            }
            try {
                if (! $disk->put($marker, json_encode(['document_id' => $id, 'key' => $key, 'backend_pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, 'pending_commit' => true], JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('No se pudo registrar la carga pendiente.');
                }
                $this->sync($disk->path($marker));
                $this->sync($disk->path('.pending'));
                if ($disk->putFileAs('files', $file, basename($key)) !== $key) {
                    throw new RuntimeException('No se pudo guardar el archivo privado.');
                }
                if (hash_file('sha256', $disk->path($key)) !== hash_file('sha256', $file->getRealPath())) {
                    throw new RuntimeException('No se pudo verificar la copia privada.');
                }
                $this->sync($disk->path($key));
                $this->sync($disk->path('files'));
                $this->sync($disk->path(''));
                $document = $persist($key);
            } catch (Throwable $exception) {
                // A failed/uncertain commit must never cause deletion of a committed historical file.
                try {
                    $this->reconcileMarker($disk, $marker, false);
                } catch (Throwable) {
                    Log::warning('fleet_document.compensation_pending', ['upload_id' => $id]);
                }
                throw $exception;
            }
            // With an enclosing transaction, retain the receipt until the real commit.
            DB::afterCommit(function () use ($disk, $marker, $id): void {
                try {
                    // A concurrent replay may return another upload's committed document.
                    // Reconcile the staged candidate instead of orphaning its bytes.
                    $this->reconcileMarker($disk, $marker);
                } catch (Throwable) {
                    Log::warning('fleet_document.receipt_pending', ['upload_id' => $id]);
                }
            });

            return $document;
        });
    }

    private function sync(string $path): void
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('No se pudo verificar la durabilidad del almacenamiento privado.');
        }
        try {
            if (! @fsync($handle)) {
                throw new RuntimeException('No se pudo confirmar la durabilidad del almacenamiento privado.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function reconcileMarker(FilesystemAdapter $disk, string $marker, bool $deferActiveTransaction = true): bool
    {
        if (! $disk->exists($marker)) {
            return true;
        }
        $receipt = json_decode($disk->get($marker), true, flags: JSON_THROW_ON_ERROR);
        $key = $receipt['key'] ?? '';
        if (! $this->validKey($key) || $marker !== '.pending/'.($receipt['document_id'] ?? '').'.json'
            || ! str_starts_with($key, 'files/'.$receipt['document_id'].'.')) {
            throw new RuntimeException('Registro de almacenamiento pendiente inválido.');
        }
        if ($receipt['pending_commit'] ?? false) {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $ownPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            $ownerPid = (int) ($receipt['backend_pid'] ?? 0);
            if (($deferActiveTransaction && $ownerPid === $ownPid && DB::transactionLevel() > 0)
                || ($ownerPid !== $ownPid && DB::selectOne('SELECT 1 AS active FROM pg_stat_activity WHERE pid = ? AND xact_start IS NOT NULL', [$ownerPid]) !== null)) {
                // An enclosing transaction may still commit a document invisible to this connection.
                return false;
            }
        }
        // Do not delete any file referenced by history, even if the receipt is inconsistent.
        $referenced = Document::query()->where('storage_key', $key)->exists();
        if ($referenced && ! $disk->exists($key)) {
            throw new RuntimeException('Un archivo histórico requiere recuperación; se conserva el registro pendiente.');
        }
        if (! $referenced && ! $deferActiveTransaction) {
            // The operation is known rolled back; a failed cleanup can be retried immediately.
            $receipt['pending_commit'] = false;
            if (! $disk->put($marker, json_encode($receipt, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('No se pudo registrar la compensación pendiente.');
            }
        }
        if (! $referenced && ! $disk->delete($key)) {
            throw new RuntimeException('No se pudo compensar la carga privada.');
        }
        if (! $disk->delete($marker)) {
            throw new RuntimeException('No se pudo cerrar el registro pendiente.');
        }

        return true;
    }

    public function reconcile(): int
    {
        return $this->safe(fn () => $this->synchronized(function (FilesystemAdapter $disk): int {
            $markers = $disk->files('.pending');
            $resolved = 0;
            foreach ($markers as $marker) {
                $resolved += (int) $this->reconcileMarker($disk, $marker);
            }

            return $resolved;
        }));
    }

    private function validKey(string $key): bool
    {
        return preg_match('/\Afiles\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(pdf|jpg|png)\z/D', $key) === 1;
    }

    /** Sanitize console reconciliation and deferred streaming failures as well as HTTP uploads. */
    private function safe(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface || $exception instanceof DocumentOperationFailed) {
                throw $exception;
            }
            throw new DocumentOperationFailed('Fallo de almacenamiento documental.', previous: $exception);
        }
    }

    public function download(Document $document): StreamedResponse
    {
        return $this->safe(function () use ($document): StreamedResponse {
            $key = $document->storage_key;
            abort_unless($this->validKey($key), 404);
            abort_unless($this->disk()->exists($key), 404);
            $extension = pathinfo($key, PATHINFO_EXTENSION);
            $response = $this->disk()->download($key, 'document-'.$document->id.'.'.$extension, [
                'Content-Type' => ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$extension],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
            $stream = $response->getCallback();
            $response->setCallback(fn () => $this->safe($stream));

            return $response;
        });
    }
}
