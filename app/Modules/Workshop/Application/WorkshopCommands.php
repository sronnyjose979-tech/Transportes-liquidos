<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Durable command receipts and bounded retries for Workshop transactions only. */
class WorkshopCommands
{
    public function run(User $actor, string $scope, string $key, array $payload, Closure $authorize, Closure $execute, ?string $operationId = null): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Los comandos de Taller requieren una transacción propia.');
        }
        if (! Str::isUuid($key) || trim($scope) === '') {
            throw ValidationException::withMessages(['request_key' => 'Clave UUID y ámbito estable requeridos.']);
        }
        $fingerprint = $this->fingerprint($payload);
        $operationId ??= (string) Str::uuid();
        if (! Str::isUuid($operationId)) {
            throw new LogicException('Identidad de operación inválida.');
        }
        $attempts = (int) config('workshop.max_attempts');
        $budget = (int) config('workshop.transaction_budget_ms');
        $lockTimeout = (int) config('workshop.lock_timeout_ms');
        $waits = config('workshop.retry_wait_ms');
        $attachmentLimit = config('workshop.attachment_limit');
        if (! is_int($attachmentLimit) || $attachmentLimit < 1) {
            throw new LogicException('Límite de adjuntos de Taller inválido.');
        }
        if ($attempts < 1 || $budget < 1 || $lockTimeout < 1 || ! is_array($waits) || $waits === []) {
            throw new LogicException('Configuración de reintentos de Taller inválida.');
        }
        foreach ($waits as $wait) {
            if (! is_array($wait) || count($wait) !== 2 || ! is_int($wait[0]) || ! is_int($wait[1]) || $wait[0] < 0 || $wait[1] < $wait[0]) {
                throw new LogicException('Esperas de Taller inválidas.');
            }
        }
        $deadline = hrtime(true) + $budget * 1000000;
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $scope, $key, $payload, $authorize, $execute, $fingerprint, $operationId, $deadline, $lockTimeout, $attachmentLimit): array {
                    $remaining = $this->remaining($deadline);
                    DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                    DB::select("SELECT set_config('workshop.attachment_limit', ?, true)", [(string) $attachmentLimit]);
                    DB::select("SELECT set_config('transaction_timeout', ?, true), set_config('statement_timeout', ?, true), set_config('lock_timeout', ?, true)", [(string) $remaining, (string) $remaining, (string) min($lockTimeout, $remaining)]);
                    DB::select('SELECT pg_advisory_xact_lock(873210)');
                    DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                    $current = User::query()->lockForUpdate()->find($actor->id);
                    if ($current === null || ! $current->is_active) {
                        throw new AuthorizationException('Cuenta no activa.');
                    }
                    DB::table('user_roles')->where('user_id', $current->id)->lockForUpdate()->get();
                    $authorize($current);
                    $receipt = DB::table('workshop_operations')->where('actor_id', $current->id)
                        ->where('idempotency_scope', $scope)->where('request_key', $key)->first();
                    if ($receipt !== null) {
                        if (! hash_equals($receipt->request_fingerprint, $fingerprint)) {
                            throw ValidationException::withMessages(['request_key' => 'Clave reutilizada con contenido distinto.']);
                        }

                        return $this->canonical(json_decode($receipt->confirmed_result, true, flags: JSON_THROW_ON_ERROR));
                    }
                    $command = $execute($current, $operationId);
                    $result = $this->canonical($command['result']);
                    $this->record($current, $scope, $key, $payload, $command['operation'], $result, $operationId);
                    $remaining = $this->remaining($deadline);
                    DB::select("SELECT set_config('statement_timeout', ?, true)", [(string) $remaining]);
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                    DB::statement('SET CONSTRAINTS ALL DEFERRED');
                    $this->remaining($deadline);

                    return $result;
                });
            } catch (QueryException $exception) {
                $state = $exception->errorInfo[0] ?? null;
                if (! in_array($state, ['40001', '40P01', '55P03'], true) || DB::transactionLevel() !== 0 || $attempt >= $attempts) {
                    throw $exception;
                }
                $wait = $waits[min($attempt - 1, count($waits) - 1)];
                $delay = random_int($wait[0], $wait[1]);
                if (hrtime(true) + $delay * 1000000 >= $deadline) {
                    throw $exception;
                }
                usleep($delay * 1000);
            }
        }
    }

    public function record(User $actor, string $scope, string $key, array $payload, array $operation, array $result = [], ?string $id = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('El recibo y sus efectos deben compartir una transacción.');
        }
        $id ??= (string) Str::uuid();
        $row = array_merge($operation, [
            'id' => $id, 'actor_id' => $actor->id, 'request_key' => $key,
            'idempotency_scope' => $scope, 'request_fingerprint' => $this->fingerprint($payload),
            'confirmed_result' => $result,
        ]);
        $row['occurred_at'] ??= now()->toIso8601String();
        $row['after_values'] ??= [];
        foreach (['before_values', 'after_values', 'confirmed_result'] as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $value = $row[$column];
                if (is_string($value)) {
                    // Validate shape, then retain PostgreSQL's exact NUMERIC JSON text.
                    // Decoding and encoding it would pass large decimals through float.
                    if (! is_object(json_decode($value, flags: JSON_THROW_ON_ERROR))) {
                        throw new LogicException('Snapshot de Taller debe ser un objeto JSON.');
                    }

                    continue;
                }
                $row[$column] = json_encode((object) $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        DB::table('workshop_operations')->insert($row);

        return $id;
    }

    public function authorizeRoles(User $actor, array $roles): void
    {
        if (! $actor->is_active || ! $actor->roles()->whereIn('name', $roles)->exists()) {
            throw new AuthorizationException('Operación de Taller no autorizada.');
        }
    }

    public function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(mixed $value): mixed
    {
        if (is_float($value)) {
            throw ValidationException::withMessages(['content' => 'Comunicar cantidades decimales como texto exacto.']);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    private function remaining(int $deadline): int
    {
        $remaining = (int) floor(($deadline - hrtime(true)) / 1000000);
        if ($remaining < 1) {
            throw new LogicException('Presupuesto transaccional de Taller agotado; consultar recibo con la misma clave.');
        }

        return $remaining;
    }
}
