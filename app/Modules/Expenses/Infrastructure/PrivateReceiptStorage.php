<?php

namespace App\Modules\Expenses\Infrastructure;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PrivateReceiptStorage
{
    private function synchronized(Closure $work): mixed
    {
        $disk = Storage::disk('fleet_documents');
        $disk->makeDirectory('expenses/.pending');
        $handle = @fopen($disk->path('expenses/.coordination.lock'), 'c');
        if (! $handle || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('No se pudo coordinar el almacenamiento.');
        }
        try {
            return $work($disk);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Store before the short database transaction; reconcile only after a known result. */
    public function register(UploadedFile $file, Closure $persist): array
    {
        return $this->synchronized(function ($disk) use ($file, $persist): array {
            $id = (string) Str::uuid();
            $key = 'expenses/files/'.$id.'.'.strtolower($file->getClientOriginalExtension());
            $marker = 'expenses/.pending/'.$id.'.json';
            $payload = ['id' => $id, 'key' => $key, 'backend_pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];
            try {
                if (! $disk->put($marker, json_encode($payload, JSON_THROW_ON_ERROR)) || $disk->putFileAs('expenses/files', $file, basename($key)) !== $key) {
                    throw new RuntimeException('No se pudo guardar el comprobante privado.');
                }
                $result = $persist($id, $key);
                $this->reconcileOne($disk, $marker);

                return $result;
            } catch (Throwable $error) {
                try {
                    $this->reconcileOne($disk, $marker);
                } catch (Throwable) {
                    Log::warning('expense_receipt.compensation_pending', ['upload_id' => $id]);
                }
                throw $error;
            }
        });
    }

    private function reconcileOne($disk, string $marker): bool
    {
        if (! $disk->exists($marker)) {
            return true;
        }
        $data = json_decode($disk->get($marker), true, flags: JSON_THROW_ON_ERROR);
        $key = $data['key'] ?? '';
        if (! preg_match('/\Aexpenses\/files\/[a-f0-9-]{36}\.(pdf|jpg|png)\z/D', $key) || $marker !== 'expenses/.pending/'.($data['id'] ?? '').'.json' || ! str_starts_with($key, 'expenses/files/'.$data['id'].'.')) {
            throw new RuntimeException('Registro pendiente inválido.');
        }
        DB::select('SELECT pg_stat_clear_snapshot()');
        $own = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $owner = (int) ($data['backend_pid'] ?? 0);
        if (($owner === $own && DB::transactionLevel() > 0) || ($owner !== $own && DB::selectOne('SELECT 1 FROM pg_stat_activity WHERE pid=? AND xact_start IS NOT NULL', [$owner]))) {
            return false;
        }
        $referenced = DB::table('expense_receipts')->where('storage_key', $key)->exists();
        if ($referenced && ! $disk->exists($key)) {
            throw new RuntimeException('Comprobante histórico pendiente de recuperación.');
        }
        if (! $referenced && ! $disk->delete($key)) {
            throw new RuntimeException('No se pudo compensar el archivo pendiente.');
        }
        if (! $disk->delete($marker)) {
            throw new RuntimeException('No se pudo cerrar la compensación.');
        }

        return true;
    }

    public function reconcile(): int
    {
        return $this->synchronized(function ($disk): int {
            $count = 0;
            foreach ($disk->files('expenses/.pending') as $marker) {
                $count += (int) $this->reconcileOne($disk, $marker);
            }

            return $count;
        });
    }

    public function download(object $receipt)
    {
        abort_unless(preg_match('/\Aexpenses\/files\/[a-f0-9-]{36}\.(pdf|jpg|png)\z/D', $receipt->storage_key), 404);
        $disk = Storage::disk('fleet_documents');
        abort_unless($disk->exists($receipt->storage_key), 404);

        return $disk->download($receipt->storage_key, 'comprobante-'.$receipt->id.'.'.pathinfo($receipt->storage_key, PATHINFO_EXTENSION), ['Content-Type' => $receipt->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
