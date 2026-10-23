<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\BillingAndPayments\Application\Money;
use App\Modules\TransportAndDispatch\Models\TransportChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;
use Tests\Support\BillingFixtures;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class BillingPaymentsTest extends PostgresTestCase
{
    use BillingFixtures, WorkshopSchema;
    use TransportFixtures {
        change as private fixtureChange;
    }

    protected function change(Model $target, array $data, ?User $actor = null): TransportChange
    {
        return DB::transaction(fn () => $this->fixtureChange($target, $data, $actor));
    }

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function prepared(): array
    {
        $this->prepareWorkshopSchema();
        DB::table('billing_guard')->insert(['id' => 1, 'version' => 0]);
        $trip = DB::transaction(fn () => $this->closedTrip());
        $this->actingAs($trip->registeredBy);

        return [$trip->registeredBy, $trip];
    }

    public function test_only_administrator_approves_and_completed_trip_with_approved_price_is_eligible(): void
    {
        [$admin, $trip] = $this->prepared();
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary);
        $body = ['request_key' => (string) Str::uuid(), 'amount' => '123.45', 'currency' => 'CRC', 'reason' => 'Precio pactado'];
        $id = $this->postJson('/api/billing/trips/'.$trip->id.'/amounts', $body)->assertCreated()->json('data.amount_id');
        $invoice = ['request_key' => (string) Str::uuid(), 'customer_id' => $trip->transportRequest->customer_id, 'items' => [['trip_id' => $trip->id, 'price_proposal_id' => $id, 'description' => 'Servicio pactado']], 'reason' => 'Documento interno'];
        $this->postJson('/api/billing/invoices', $invoice)->assertUnprocessable();
        $decision = ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Autorizado'];
        $this->postJson('/api/billing/amounts/'.$id.'/decisions', $decision)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/billing/amounts/'.$id.'/decisions', $decision)->assertOk();
        $this->actingAs($secretary)->postJson('/api/billing/invoices', $invoice)->assertCreated();
        $this->postJson('/api/billing/invoices', [...$invoice, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('billing_invoice_items', 1);
        $pending = $this->trip($this->request($admin));
        $this->actingAs($admin);
        $this->approvedBillingAmount($pending);
        $this->postJson('/api/billing/invoices', [...$invoice, 'request_key' => (string) Str::uuid(), 'customer_id' => $pending->transportRequest->customer_id, 'items' => [$this->billingItem($pending)]])->assertUnprocessable();
        $this->assertDatabaseCount('billing_invoices', 1);
    }

    public function test_cancelled_trip_is_not_billable_even_with_approved_amount(): void
    {
        [$admin] = $this->prepared();
        $trip = $this->trip($this->request($admin));
        $this->change($trip, ['execution_status' => 'cancelled', 'cancelled_at' => '2026-10-14T08:00:00-06:00', 'cancelled_by_id' => $admin->id, 'cancellation_reason' => 'Cancelación sintética']);
        $trip->refresh();
        $this->approvedBillingAmount($trip);
        $this->postJson('/api/billing/invoices', ['request_key' => (string) Str::uuid(), 'customer_id' => $trip->transportRequest->customer_id, 'items' => [$this->billingItem($trip)], 'reason' => 'Cancelado'])->assertUnprocessable();
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_integer_money_exact_round_half_up_at_decimal_boundaries(): void
    {
        $money = Money::class;
        $this->assertSame('1.01', $money::roundHalfUp('1.005'));
        $this->assertSame('1.00', $money::roundHalfUp('1.004999'));
        $this->assertSame('10.00', $money::roundHalfUp('9.995'));
        $this->assertSame('999999999999.99', $money::format($money::minor('999999999999.99')));
        $this->assertSame('0.03', $money::format($money::minor('0.01') + $money::minor('0.02')));
        $this->assertSame('99999999999999.00', $money::format($money::minor('999999999999.99') * 100));
    }

    public function test_partial_payments_total_and_same_intent_recovery_preserve_exact_balance(): void
    {
        [, $trip] = $this->prepared();
        $this->approvedBillingAmount($trip, '100.03');
        $invoice = $this->billingInvoice($trip);
        $url = '/api/billing/invoices/'.$invoice.'/payments';
        $body = $this->billingPayment('33.34');
        $payment = $this->postJson($url, $body)->assertCreated()->json('data.payment_id');
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.payment_id', $payment);
        $this->postJson($url, [...$body, 'amount' => '33.35'])->assertConflict();
        $this->getJson('/api/billing/invoices/'.$invoice)->assertOk()->assertJsonPath('data.total', '100.03')->assertJsonPath('data.paid', '33.34')->assertJsonPath('data.balance', '66.69')->assertJsonPath('data.status', 'partially_paid');
        $this->postJson($url, $this->billingPayment('66.70'))->assertUnprocessable();
        foreach (['0', '-1', '1.001'] as $amount) {
            $this->postJson($url, $this->billingPayment($amount))->assertUnprocessable();
        }
        $this->postJson($url, $this->billingPayment('1', 'USD'))->assertUnprocessable();
        $this->postJson($url, $this->billingPayment('66.69'))->assertCreated();
        $this->getJson('/api/billing/invoices/'.$invoice)->assertOk()->assertJsonPath('data.paid', '100.03')->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.status', 'paid');
        $this->postJson($url, $this->billingPayment('0.01'))->assertUnprocessable();
        $this->assertDatabaseCount('billing_payments', 2);
    }

    public function test_crc_usd_grouping_customer_and_immutable_approved_amounts(): void
    {
        [, $trip] = $this->prepared();
        $amount = $this->approvedBillingAmount($trip, '0.03', 'USD');
        $invoice = $this->billingInvoice($trip);
        $this->getJson('/api/billing/invoices/'.$invoice)->assertOk()->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.balance', '0.03');
        $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $this->billingPayment('0.01', 'USD'))->assertCreated();
        $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $this->billingPayment('0.02', 'USD'))->assertCreated();
        $this->getJson('/api/billing/invoices/'.$invoice)->assertOk()->assertJsonPath('data.balance', '0.00');
        $this->rejected(fn () => DB::table('billing_trip_amounts')->where('id', $amount)->update(['amount' => '999.99']));
        $this->rejected(fn () => DB::table('billing_payments')->where('invoice_id', $invoice)->delete());
        $this->postJson('/api/billing/trips/'.$trip->id.'/amounts', ['request_key' => (string) Str::uuid(), 'amount' => '0.04', 'currency' => 'USD', 'reason' => 'Corrección posterior', 'supersedes_id' => $amount])->assertUnprocessable();
        $other = DB::transaction(fn () => $this->closedTrip());
        $this->approvedBillingAmount($other);
        $this->postJson('/api/billing/invoices', ['request_key' => (string) Str::uuid(), 'customer_id' => $trip->transportRequest->customer_id, 'items' => [$this->billingItem($other)], 'reason' => 'Cliente incorrecto'])->assertUnprocessable();
    }

    public function test_pre_invoice_approved_correction_preserves_previous_price_and_rejects_stale_reference(): void
    {
        [, $trip] = $this->prepared();
        $old = $this->approvedBillingAmount($trip, '10.01');
        $new = $this->postJson('/api/billing/trips/'.$trip->id.'/amounts', ['request_key' => (string) Str::uuid(), 'amount' => '11.02', 'currency' => 'CRC', 'reason' => 'Corrección documentada', 'supersedes_id' => $old])->assertCreated()->json('data.amount_id');
        $this->postJson('/api/billing/amounts/'.$new.'/decisions', ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Corrección revisada'])->assertOk();
        $this->postJson('/api/billing/invoices', ['request_key' => (string) Str::uuid(), 'customer_id' => $trip->transportRequest->customer_id, 'items' => [['trip_id' => $trip->id, 'price_proposal_id' => $old, 'description' => 'Importe anterior']], 'reason' => 'Referencia obsoleta'])->assertUnprocessable();
        $invoice = $this->billingInvoice($trip);
        $this->getJson('/api/billing/invoices/'.$invoice)->assertOk()->assertJsonPath('data.total', '11.02');
        $this->assertDatabaseHas('billing_trip_amounts', ['id' => $old, 'amount' => '10.01']);
        $this->assertDatabaseHas('billing_trip_amounts', ['id' => $new, 'supersedes_id' => $old]);
        $this->assertDatabaseCount('billing_amount_decisions', 2);
    }

    public function test_pending_price_correction_does_not_revoke_existing_approval(): void
    {
        [, $trip] = $this->prepared();
        $old = $this->approvedBillingAmount($trip, '10.01');
        $new = $this->postJson('/api/billing/trips/'.$trip->id.'/amounts', ['request_key' => (string) Str::uuid(), 'amount' => '11.02', 'currency' => 'CRC', 'reason' => 'Propuesta pendiente', 'supersedes_id' => $old])->assertCreated()->json('data.amount_id');
        $invoice = $this->billingInvoice($trip);
        $this->getJson('/api/billing/invoices/'.$invoice)->assertOk()->assertJsonPath('data.total', '10.01');
        $this->postJson('/api/billing/amounts/'.$new.'/decisions', ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Demasiado tarde'])->assertUnprocessable();
    }

    public function test_same_customer_grouping_and_currency_mix_rejection(): void
    {
        [, $trip] = $this->prepared();
        $this->approvedBillingAmount($trip, '10.01');
        $second = $this->trip($trip->transportRequest);
        $this->change($second, ['execution_status' => 'in_progress', 'officially_started_at' => '2026-10-11T12:00:00Z', 'started_by_id' => $second->registered_by_id]);
        $this->change($second, ['execution_status' => 'completed', 'officially_completed_at' => '2026-10-12T12:00:00Z', 'completed_by_id' => $second->registered_by_id]);
        $second->refresh();
        $this->approvedBillingAmount($second, '20.02', 'USD');
        $body = ['request_key' => (string) Str::uuid(), 'customer_id' => $trip->transportRequest->customer_id, 'items' => [$this->billingItem($trip), $this->billingItem($second)], 'reason' => 'Agrupación comercial'];
        $this->postJson('/api/billing/invoices', $body)->assertUnprocessable();
        $old = $body['items'][1]['price_proposal_id'];
        $new = $this->postJson('/api/billing/trips/'.$second->id.'/amounts', ['request_key' => (string) Str::uuid(), 'amount' => '20.02', 'currency' => 'CRC', 'reason' => 'Moneda acordada corregida', 'supersedes_id' => $old])->assertCreated()->json('data.amount_id');
        $this->postJson('/api/billing/amounts/'.$new.'/decisions', ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Confirmado'])->assertOk();
        $body['items'][1] = $this->billingItem($second);
        $invoice = $this->postJson('/api/billing/invoices', $body)->assertCreated()->json('data.invoice_id');
        $this->getJson('/api/billing/invoices/'.$invoice)->assertOk()->assertJsonPath('data.total', '30.03')->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('billing_invoice_items', 2);
    }

    public function test_sql_enforces_administrator_approval_and_exact_positive_money(): void
    {
        [$admin, $trip] = $this->prepared();
        $secretary = $this->actor('secretary');
        $amount = $this->postJson('/api/billing/trips/'.$trip->id.'/amounts', ['request_key' => (string) Str::uuid(), 'amount' => '1.00', 'currency' => 'CRC', 'reason' => 'Pendiente'])->assertCreated()->json('data.amount_id');
        $this->rejected(fn () => DB::table('billing_amount_decisions')->insert(['id' => (string) Str::uuid(), 'amount_id' => $amount, 'decision' => 'approved', 'reason' => 'No autorizado', 'decided_by_id' => $secretary->id]));
        foreach (['-1', '1.001'] as $value) {
            $other = $this->trip($this->request($admin));
            $this->rejected(fn () => DB::table('billing_trip_amounts')->insert(['id' => (string) Str::uuid(), 'trip_id' => $other->id, 'amount' => $value, 'currency' => 'CRC', 'reason' => 'Valor SQL inválido', 'requested_by_id' => $admin->id]));
        }
        $this->assertDatabaseCount('billing_amount_decisions', 0);
    }

    public function test_zero_price_invoice_has_zero_balance_and_needs_no_fictitious_payment(): void
    {
        [, $trip] = $this->prepared();
        $this->approvedBillingAmount($trip, '0.00');
        $invoice = $this->billingInvoice($trip);
        $this->getJson('/api/billing/invoices/'.$invoice)->assertOk()->assertJsonPath('data.total', '0.00')->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.status', 'paid');
        $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $this->billingPayment('0.01'))->assertUnprocessable();
        $this->assertDatabaseCount('billing_payments', 0);
    }

    public function test_unauthorized_inactive_revoked_accounts_and_actor_forgery_are_rejected(): void
    {
        [$admin, $trip] = $this->prepared();
        $this->approvedBillingAmount($trip);
        $invoice = $this->billingInvoice($trip);
        foreach (['driver', 'mechanic'] as $role) {
            $this->actingAs($this->actor($role))->getJson('/api/billing/invoices')->assertForbidden();
            $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $this->billingPayment('1'))->assertForbidden();
        }
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary)->getJson('/api/billing/invoices')->assertOk();
        $this->postJson('/api/billing/invoices/'.$invoice.'/payments', [...$this->billingPayment('1'), 'actor_id' => $admin->id])->assertUnprocessable();
        $secretary->roles()->detach();
        $this->actingAs($secretary->fresh())->getJson('/api/billing/invoices')->assertForbidden();
        $admin->forceFill(['is_active' => false])->save();
        $this->actingAs($admin->fresh())->getJson('/api/billing/invoices')->assertForbidden();
    }

    public function test_invoice_filters_accept_independent_date_bounds_customer_and_derived_status(): void
    {
        [, $trip] = $this->prepared();
        $this->approvedBillingAmount($trip);
        $invoice = $this->billingInvoice($trip);
        $today = now('America/Costa_Rica')->toDateString();
        $this->getJson('/api/billing/invoices?'.http_build_query(['to' => $today]))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/billing/invoices?'.http_build_query(['from' => $today]))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/billing/invoices?'.http_build_query(['customer_id' => $trip->transportRequest->customer_id, 'status' => 'pending', 'currency' => 'CRC']))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/billing/invoices?'.http_build_query(['customer_id' => (string) Str::uuid()]))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $this->billingPayment('10.00'))->assertCreated();
        $this->getJson('/api/billing/invoices?status=partially_paid')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/billing/invoices?status=pending')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/billing/invoices?from=2026-10-15&to=2026-10-14')->assertUnprocessable();
    }

    public function test_raw_payment_without_matching_idempotent_scope_cannot_commit(): void
    {
        [$admin, $trip] = $this->prepared();
        $this->approvedBillingAmount($trip);
        $invoice = $this->billingInvoice($trip);
        $payment = (string) Str::uuid();
        $this->rejected(function () use ($admin, $invoice, $payment): void {
            DB::table('billing_payments')->insert(['id' => $payment, 'invoice_id' => $invoice, 'amount' => '1.00', 'currency' => 'CRC', 'paid_at' => now()->toIso8601String(), 'recorded_by_id' => $admin->id, 'reason' => 'Pago SQL']);
            DB::table('billing_operations')->insert(['id' => (string) Str::uuid(), 'actor_id' => $admin->id, 'scope' => 'payment:'.Str::uuid(), 'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'payment_id' => $payment, 'result' => json_encode(['payment_id' => $payment])]);
        });
        $this->assertDatabaseCount('billing_payments', 0);
    }

    public function test_two_payment_transactions_serialize_and_cannot_overpay_even_with_direct_sql(): void
    {
        [$admin, $trip] = $this->prepared();
        $this->approvedBillingAmount($trip, '10.00');
        $invoice = $this->billingInvoice($trip);
        $config = config('database.connections.pgsql_testing');
        $other = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET search_path TO '.DB::selectOne('SELECT current_schema() AS name')->name.',public');
        $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $this->billingPayment('7.00'))->assertCreated();
        // Hold the coordinator independently of the completed service command.
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(873210)');
        $other->beginTransaction();
        $other->exec("SET LOCAL lock_timeout='100ms'");
        try {
            $other->prepare('INSERT INTO billing_payments(id,invoice_id,amount,currency,paid_at,recorded_by_id,reason) VALUES(?,?,?,?,?,?,?)')->execute([(string) Str::uuid(), $invoice, '7.00', 'CRC', now()->toIso8601String(), $admin->id, 'Concurrente']);
            $this->fail('El segundo pago debía esperar al primero.');
        } catch (PDOException $exception) {
            $this->assertSame('55P03', $exception->getCode());
            $other->rollBack();
        }
        DB::commit();
        $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $this->billingPayment('7.00'))->assertUnprocessable();
        $this->rejected(fn () => DB::table('billing_payments')->insert(['id' => (string) Str::uuid(), 'invoice_id' => $invoice, 'amount' => '4.00', 'currency' => 'CRC', 'paid_at' => now()->toIso8601String(), 'recorded_by_id' => $admin->id, 'reason' => 'Sobrepago SQL']));
        $this->getJson('/api/billing/invoices/'.$invoice)->assertOk()->assertJsonPath('data.balance', '3.00');
        $this->assertDatabaseCount('billing_payments', 1);
    }
}
