<?php

namespace App\Modules\BillingAndPayments\Application;

use App\Models\User;
use App\Support\BoundedTransaction;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BillingPayments
{
    public function propose(User $actor, string $trip, string $key, array $data): array
    {
        return $this->run($actor, 'amount:'.$trip, $key, $data, false, function () use ($actor, $trip, $data): array {
            DB::table('trips')->where('id', $trip)->firstOrFail();
            $id = (string) Str::uuid();
            DB::table('billing_trip_amounts')->insert($data + ['id' => $id, 'trip_id' => $trip, 'requested_by_id' => $actor->id]);

            return ['amount_id' => $id];
        });
    }

    public function decide(User $actor, string $amount, string $key, array $data): array
    {
        return $this->run($actor, 'decision:'.$amount, $key, $data, true, function () use ($actor, $amount, $data): array {
            DB::table('billing_trip_amounts')->where('id', $amount)->firstOrFail();
            $id = (string) Str::uuid();
            DB::table('billing_amount_decisions')->insert($data + ['id' => $id, 'amount_id' => $amount, 'decided_by_id' => $actor->id]);

            return ['decision_id' => $id];
        });
    }

    public function invoice(User $actor, string $key, array $data): array
    {
        return $this->run($actor, 'invoice', $key, $data, false, function () use ($actor, $data): array {
            $customer = DB::table('customers')->where('id', $data['customer_id'])->firstOrFail();
            $prices = [];
            foreach ($data['items'] as $item) {
                $trip = $item['trip_id'];
                $price = self::approved($trip);
                if (! $price || $price->id !== $item['price_proposal_id']) {
                    throw ValidationException::withMessages(['items' => 'El importe aprobado cambió o no existe; consulta el viaje.']);
                }
                $prices[$trip] = $price;
                $descriptions[$trip] = $item['description'];
            }
            $currencies = array_unique(array_column($prices, 'currency'));
            if (count($currencies) !== 1) {
                throw ValidationException::withMessages(['items' => 'Una factura debe utilizar una sola moneda.']);
            }
            $id = (string) Str::uuid();
            DB::table('billing_invoices')->insert(['id' => $id, 'customer_id' => $customer->id, 'customer_name_snapshot' => $customer->name, 'currency' => $currencies[0], 'reason' => $data['reason'], 'created_by_id' => $actor->id]);
            foreach ($prices as $trip => $price) {
                DB::table('billing_invoice_items')->insert(['id' => (string) Str::uuid(), 'invoice_id' => $id, 'trip_id' => $trip, 'price_proposal_id' => $price->id, 'concept' => $descriptions[$trip], 'quantity' => '1', 'amount' => $price->amount]);
            }

            return ['invoice_id' => $id];
        });
    }

    public function payment(User $actor, string $invoice, string $key, array $data): array
    {
        return $this->run($actor, 'payment:'.$invoice, $key, $data, false, function () use ($actor, $invoice, $data): array {
            DB::table('billing_invoices')->where('id', $invoice)->lockForUpdate()->firstOrFail();
            $id = (string) Str::uuid();
            DB::table('billing_payments')->insert($data + ['id' => $id, 'invoice_id' => $invoice, 'recorded_by_id' => $actor->id]);

            return ['payment_id' => $id];
        });
    }

    public static function approved(string $trip): ?object
    {
        return DB::table('billing_trip_amounts as a')->join('billing_amount_decisions as d', 'd.amount_id', '=', 'a.id')->where('a.trip_id', $trip)->where('d.decision', 'approved')->whereRaw("NOT EXISTS(WITH RECURSIVE newer AS (SELECT id FROM billing_trip_amounts WHERE supersedes_id=a.id UNION ALL SELECT b.id FROM billing_trip_amounts b JOIN newer n ON b.supersedes_id=n.id) SELECT 1 FROM newer n JOIN billing_amount_decisions decision ON decision.amount_id=n.id WHERE decision.decision='approved')")->select('a.*')->first();
    }

    private function run(User $actor, string $scope, string $key, array $data, bool $admin, Closure $work): array
    {
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
            return app(BoundedTransaction::class)->run(function () use ($actor, $scope, $key, $fingerprint, $admin, $work): array {
                DB::select('SELECT pg_advisory_xact_lock(873210)');
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                DB::table('billing_guard')->where('id', 1)->increment('version');
                $fresh = User::query()->lockForUpdate()->find($actor->id);
                if ($fresh) {
                    DB::table('user_roles')->where('user_id', $fresh->id)->lockForUpdate()->get();
                }
                if (! $fresh || ! $fresh->is_active || ! $fresh->roles()->whereIn('name', $admin ? ['administrator'] : ['administrator', 'secretary'])->exists()) {
                    throw new AuthorizationException;
                }
                $previous = DB::table('billing_operations')->where('actor_id', $actor->id)->where('scope', $scope)->where('request_key', $key)->first();
                if ($previous) {
                    if (! hash_equals($previous->fingerprint, $fingerprint)) {
                        throw new ConflictHttpException('Clave reutilizada con contenido distinto.');
                    }

                    return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                }
                $result = $work();
                DB::table('billing_operations')->insert(['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'scope' => $scope, 'request_key' => $key, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR)] + $result);

                return $result;
            });
        } catch (QueryException $error) {
            $state = $error->errorInfo[0] ?? null;
            if (in_array($state, ['23514', '23503', '23502'], true)) {
                throw ValidationException::withMessages(['operation' => 'Importe, saldo, moneda, estado o aprobación incompatible.']);
            }
            if (in_array($state, ['23505', '40001', '40P01', '55P03'], true)) {
                throw new ConflictHttpException('Operación concurrente, sustituida o ya confirmada.');
            }
            throw $error;
        }
    }
}
