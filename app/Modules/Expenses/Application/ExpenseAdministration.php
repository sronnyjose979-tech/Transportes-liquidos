<?php

namespace App\Modules\Expenses\Application;

use App\Models\User;
use App\Modules\BillingAndPayments\Application\Money;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ExpenseAdministration
{
    public static function office(User $user): bool
    {
        return $user->is_active && $user->roles()->whereIn('name', ['administrator', 'secretary'])->exists();
    }

    public static function assigned(User $user, string $trip): bool
    {
        return $user->is_active && $user->roles()->where('name', 'driver')->exists() && DB::table('driver_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('e.user_id', $user->id)->where('e.is_active', true)->where('a.trip_id', $trip)->where('a.starts_at', '<=', DB::raw('clock_timestamp()'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('driver_releases as r')->whereColumn('r.assignment_id', 'a.id'))->exists();
    }

    public function create(User $actor, string $key, array $data): array
    {
        $data['amount'] = Money::format(Money::minor($data['amount']));

        return $this->run($actor, 'create', $key, $data, false, function () use ($actor, $data): array {
            if (! self::office($actor) && (! ($data['trip_id'] ?? null) || ! self::assigned($actor, $data['trip_id']))) {
                throw new AuthorizationException;
            }
            $expense = (string) Str::uuid();
            $revision = (string) Str::uuid();
            DB::table('expenses')->insert(['id' => $expense, 'trip_id' => $data['trip_id'] ?? null, 'created_by_id' => $actor->id]);
            unset($data['trip_id']);
            DB::table('expense_revisions')->insert($data + ['id' => $revision, 'expense_id' => $expense, 'recorded_by_id' => $actor->id]);

            return ['expense_id' => $expense, 'revision_id' => $revision];
        });
    }

    public function correct(User $actor, string $expense, string $key, array $data): array
    {
        $data['amount'] = Money::format(Money::minor($data['amount']));

        return $this->run($actor, 'correction:'.$expense, $key, $data, false, function () use ($actor, $expense, $data): array {
            if (! self::office($actor)) {
                throw new AuthorizationException;
            }
            DB::table('expenses')->where('id', $expense)->firstOrFail();
            DB::table('expense_revisions')->where('id', $data['supersedes_id'])->where('expense_id', $expense)->firstOrFail();
            if (DB::table('expense_revisions')->where('supersedes_id', $data['supersedes_id'])->exists()) {
                throw new ConflictHttpException('La versión del gasto cambió. Consulta el historial.');
            }
            $revision = (string) Str::uuid();
            DB::table('expense_revisions')->insert($data + ['id' => $revision, 'expense_id' => $expense, 'recorded_by_id' => $actor->id]);

            return ['expense_id' => $expense, 'revision_id' => $revision];
        });
    }

    public function decide(User $actor, string $expense, string $key, array $data): array
    {
        return $this->run($actor, 'decision:'.$expense, $key, $data, true, function () use ($actor, $expense, $data): array {
            DB::table('expense_revisions')->where('id', $data['expected_revision_id'])->where('expense_id', $expense)->firstOrFail();
            if (DB::table('expense_revisions')->where('supersedes_id', $data['expected_revision_id'])->exists()) {
                throw new ConflictHttpException('La versión del gasto cambió. Consulta el historial.');
            }
            $id = (string) Str::uuid();
            DB::table('expense_decisions')->insert(['id' => $id, 'revision_id' => $data['expected_revision_id'], 'decision' => $data['decision'], 'reason' => $data['reason'], 'decided_by_id' => $actor->id]);

            return ['expense_id' => $expense, 'decision_id' => $id];
        });
    }

    public function receipt(User $actor, string $expense, string $key, array $data, Closure $persist): array
    {
        return $this->run($actor, 'receipt:'.$expense, $key, $data, false, function () use ($actor, $expense, $persist): array {
            $record = DB::table('expenses')->where('id', $expense)->firstOrFail();
            if (! self::office($actor) && ($record->created_by_id !== $actor->id || ! $record->trip_id || ! self::assigned($actor, $record->trip_id))) {
                throw new AuthorizationException;
            }

            return $persist();
        });
    }

    private function run(User $actor, string $scope, string $key, array $data, bool $admin, Closure $work): array
    {
        ksort($data);
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $scope, $key, $fingerprint, $admin, $work): array {
                DB::select('SELECT pg_advisory_xact_lock(873210)');
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                $fresh = User::query()->lockForUpdate()->find($actor->id);
                if ($fresh) {
                    DB::table('user_roles')->where('user_id', $fresh->id)->lockForUpdate()->get();
                }
                if (! $fresh || ! $fresh->is_active || ! $fresh->roles()->whereIn('name', $admin ? ['administrator'] : ['administrator', 'secretary', 'driver'])->exists()) {
                    throw new AuthorizationException;
                }
                $previous = DB::table('expense_operations')->where('actor_id', $actor->id)->where('scope', $scope)->where('request_key', $key)->first();
                if ($previous) {
                    if (! hash_equals($previous->fingerprint, $fingerprint)) {
                        throw new ConflictHttpException('Clave reutilizada con contenido diferente.');
                    }

                    return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                }
                $result = $work();
                $link = array_intersect_key($result, array_flip(['revision_id', 'decision_id', 'receipt_id']));
                DB::table('expense_operations')->insert(['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'scope' => $scope, 'request_key' => $key, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR)] + $link);

                return $result;
            });
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? null;
            if (in_array($state, ['23514', '23503', '23502'], true)) {
                throw ValidationException::withMessages(['operation' => 'Gasto, versión, asignación o aprobación incompatible.']);
            }
            if (in_array($state, ['23505', '40001', '40P01', '55P03'], true)) {
                throw new ConflictHttpException('Operación concurrente o ya confirmada.');
            }
            throw $e;
        }
    }
}
