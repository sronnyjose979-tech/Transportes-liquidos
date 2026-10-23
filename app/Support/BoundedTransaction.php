<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/** One attempt; receipts and business effects remain in the caller's closure. */
final class BoundedTransaction
{
    public function run(Closure $work): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('La operación requiere una transacción propia sin bloqueos previos.');
        }
        $lock = config('operations.lock_timeout_ms');
        $statement = config('operations.statement_timeout_ms');
        if (! is_int($lock) || ! is_int($statement) || $lock < 1 || $statement < $lock) {
            throw new LogicException('Límites transaccionales inválidos.');
        }
        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            $connection->select("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)", [(string) $lock, (string) $statement]);
            $result = $work();
            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            // commit() keeps Laravel's level until PDO confirms success, unlike
            // transaction(). Roll back physical state even for deferred failures.
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // Clear pending after-commit work too; purging PDO alone leaves
                // transaction records in Laravel's shared manager.
                try {
                    app('db.transactions')->rollback($connection->getName(), 0);
                } catch (Throwable) {
                    // Cleanup must never replace or log the original SQL error.
                }
                DB::purge($connection->getName());
            }
            if ($exception instanceof PDOException) {
                $state = $exception->errorInfo[0] ?? null;
                if (in_array($state, ['40001', '40P01', '55P03', '57014'], true)) {
                    throw new ConflictHttpException('No se confirmó la operación por contención o tiempo de espera. Consulta el resultado y conserva la misma clave al reenviar.');
                }
                // Unknown/connection failures may have committed. Discard the
                // session; never retry or infer the outcome from rollback alone.
                if (! $exception instanceof QueryException || (is_string($state) && str_starts_with($state, '08'))) {
                    DB::purge($connection->getName());
                }
            }
            throw $exception;
        }
    }
}
