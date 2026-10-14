<?php

namespace App\Modules\Inventory\Application;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class InventoryLedger
{
    public function correction(User $actor, string $key, array $data): array
    {
        return $this->run($actor, 'correction', $key, $data, ['administrator', 'secretary'], function (User $fresh) use ($data): array {
            $id = (string) Str::uuid();
            DB::table('inventory_corrections')->insert(array_merge($data, ['id' => $id, 'requested_by_id' => $fresh->id]));

            return ['correction_id' => $id];
        });
    }

    public function decide(User $actor, string $id, string $key, array $data): array
    {
        return $this->run($actor, 'decision:'.$id, $key, $data, ['administrator'], function (User $fresh) use ($data, $id): array {
            DB::table('inventory_corrections')->where('id', $id)->firstOrFail();
            $decision = (string) Str::uuid();
            DB::table('inventory_decisions')->insert(array_merge($data, ['id' => $decision, 'correction_id' => $id, 'decided_by_id' => $fresh->id]));

            return ['decision_id' => $decision];
        });
    }

    public function apply(User $actor, string $id, string $key, array $data): array
    {
        return $this->run($actor, 'apply:'.$id, $key, $data, ['administrator'], function (User $fresh) use ($id, $data): array {
            DB::table('inventory_decisions')->where('id', $id)->firstOrFail();
            $application = (string) Str::uuid();
            DB::table('inventory_applications')->insert(['id' => $application, 'decision_id' => $id, 'applied_by_id' => $fresh->id, 'occurred_at' => $data['occurred_at']]);

            return ['application_id' => $application, 'movement_id' => DB::table('inventory_movements')->where('application_id', $application)->value('id')];
        });
    }

    public function returnParts(User $actor, string $key, array $data): array
    {
        return $this->run($actor, 'return', $key, $data, ['administrator', 'secretary'], function (User $fresh) use ($data): array {
            $id = (string) Str::uuid();
            DB::table('inventory_returns')->insert(array_merge($data, ['id' => $id, 'received_by_id' => $fresh->id]));

            return ['return_id' => $id, 'movement_id' => DB::table('inventory_movements')->where('return_id', $id)->value('id')];
        });
    }

    /** Caller owns the purchase receipt transaction; source uniqueness guarantees one entry. */
    public function receive(string $partId, string $quantity, string $unit, string $receiptItemId, int $actorId, string $occurredAt, string $operationId): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Recepción e inventario requieren una transacción común.');
        }
        $id = (string) Str::uuid();
        DB::table('inventory_movements')->insert(['id' => $id, 'spare_part_id' => $partId, 'unit_snapshot' => $unit, 'kind' => 'receipt', 'warehouse_delta' => $quantity, 'custody_delta' => '0', 'consumed_delta' => '0', 'purchase_receipt_item_id' => $receiptItemId, 'actor_id' => $actorId, 'occurred_at' => $occurredAt]);

        return $id;
    }

    private function run(User $actor, string $scope, string $key, array $data, array $roles, Closure $work): array
    {
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['request_key' => 'Clave UUID requerida.']);
        }
        $canonical = function (array $value) use (&$canonical): array {
            ksort($value);
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $value;
        };
        $fingerprint = hash('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $scope, $key, $roles, $work, $fingerprint): array {
                DB::select('SELECT pg_advisory_xact_lock(873210)');
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                $fresh = User::query()->lockForUpdate()->find($actor->id);
                if ($fresh) {
                    DB::table('user_roles')->where('user_id', $fresh->id)->lockForUpdate()->get();
                }
                if (! $fresh || ! $fresh->is_active || ! $fresh->roles()->whereIn('name', $roles)->exists()) {
                    throw new AuthorizationException('Operación no autorizada.');
                }
                $previous = DB::table('inventory_operations')->where('actor_id', $fresh->id)->where('scope', $scope)->where('request_key', $key)->first();
                if ($previous) {
                    if (! hash_equals($previous->fingerprint, $fingerprint)) {
                        throw new ConflictHttpException('Clave reutilizada con contenido distinto.');
                    }

                    return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                }
                $result = $work($fresh);
                DB::table('inventory_operations')->insert(['id' => (string) Str::uuid(), 'actor_id' => $fresh->id, 'scope' => $scope, 'request_key' => $key, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);

                return $result;
            });
        } catch (QueryException $error) {
            $state = $error->errorInfo[0] ?? null;
            if (in_array($state, ['23514', '23503', '23502'], true)) {
                throw ValidationException::withMessages(['operation' => 'Cantidad, relación o estado incompatible; consulta existencias y autorizaciones.']);
            }
            if (in_array($state, ['23505', '40001', '40P01', '55P03'], true)) {
                throw new ConflictHttpException('Operación concurrente o ya confirmada.');
            }
            throw $error;
        }
    }
}
