<?php

namespace App\Modules\Workshop\Infrastructure;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PrivateWorkshopFiles
{
    public function locked(string $reservation, Closure $callback): mixed
    {
        if (! Str::isUuid($reservation)) {
            throw new RuntimeException('Reserva inválida.');
        }
        $directory = $this->root().'/locks';
        $this->directory($directory);
        $handle = fopen($directory.'/'.$reservation.'.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('No se pudo coordinar la carga.');
        }
        chmod($directory.'/'.$reservation.'.lock', 0600);
        $deadline = hrtime(true) + (int) config('workshop.flock_timeout_ms', 2000) * 1000000;
        try {
            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('Carga ocupada; reintentar con la misma clave.');
                }
                usleep(10000);
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function path(string $key): string
    {
        if (! preg_match('/^[0-9a-f-]{36}\.(pdf|jpg|jpeg|png)$/D', $key) || ! Str::isUuid(explode('.', $key)[0])) {
            throw new RuntimeException('Clave privada inválida.');
        }

        return $this->root().'/bytes/'.$key;
    }

    public function write(string $source, string $key, array $expected): void
    {
        $this->verify($source, $expected);
        $path = $this->path($key);
        $this->directory(dirname($path));
        if (is_file($path)) {
            $this->verify($path, $expected);

            return;
        }
        $input = fopen($source, 'rb');
        $output = fopen($path, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new RuntimeException('Transferencia privada no disponible.');
        }
        try {
            chmod($path, 0600);
            if (stream_copy_to_stream($input, $output, $expected['size_bytes'] + 1) !== $expected['size_bytes'] || ! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('Transferencia incompleta; conservar reserva para recuperación.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        $directoryHandle = fopen(dirname($path), 'r');
        if ($directoryHandle === false) {
            throw new RuntimeException('No se pudo verificar durabilidad del directorio privado.');
        }
        try {
            if (! fsync($directoryHandle)) {
                throw new RuntimeException('Durabilidad del directorio no confirmada; conservar reserva.');
            }
        } finally {
            fclose($directoryHandle);
        }
        $this->verify($path, $expected);
    }

    public function verify(string $path, array $expected): void
    {
        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) !== $expected['size_bytes'] || ! hash_equals($expected['checksum'], hash_file('sha256', $path)) || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== $expected['mime']) {
            throw ValidationException::withMessages(['file' => 'Archivo ausente o contenido distinto del esperado.']);
        }
        if ($expected['mime'] !== 'application/pdf' && @getimagesize($path) === false) {
            throw ValidationException::withMessages(['file' => 'Imagen inválida.']);
        }
        if ($expected['mime'] === 'application/pdf') {
            $handle = fopen($path, 'rb');
            try {
                $header = fread($handle, 5);
                fseek($handle, -min(1024, $expected['size_bytes']), SEEK_END);
                $tail = stream_get_contents($handle);
            } finally {
                fclose($handle);
            }
            if ($header !== '%PDF-' || ! str_contains($tail, '%%EOF')) {
                throw ValidationException::withMessages(['file' => 'Estructura PDF inválida.']);
            }
        }
    }

    public function remove(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('Liberación confirmada; limpieza pendiente.');
        }
    }

    private function root(): string
    {
        return (string) config('workshop.storage_root', storage_path('app/private/workshop'));
    }

    private function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Almacenamiento privado no disponible.');
        }
        chmod($path, 0700);
    }
}
