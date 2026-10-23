<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
        try {
            return DB::transaction(function () use ($work, $lock, $statement): mixed {
                DB::select("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)", [(string) $lock, (string) $statement]);

                return $work();
            }, 1);
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['40001', '40P01', '55P03', '57014'], true)) {
                throw new ConflictHttpException('No se confirmó la operación por contención o tiempo de espera. Consulta el resultado y conserva la misma clave al reenviar.');
            }
            throw $exception;
        }
    }
}
