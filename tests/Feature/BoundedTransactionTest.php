<?php

namespace Tests\Feature;

use App\Modules\BillingAndPayments\Application\BillingPayments;
use App\Modules\Expenses\Application\ExpenseAdministration;
use App\Modules\Inventory\Application\InventoryLedger;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Purchasing\Application\PurchaseFulfillment;
use App\Modules\Purchasing\Application\PurchaseRequestAdministration;
use App\Support\BoundedTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class BoundedTransactionTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function rival(): PDO
    {
        $c = config('database.connections.pgsql_testing');

        return new PDO('pgsql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'], $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function test_all_five_services_bound_advisory_wait_without_effects_or_receipts(): void
    {
        $this->prepareWorkshopSchema();
        $actor = $this->actor();
        $other = $this->rival();
        $other->beginTransaction();
        $other->query('SELECT pg_advisory_xact_lock(873210)');
        config(['operations.lock_timeout_ms' => 50]);
        $key = (string) Str::uuid();
        $commands = [
            fn () => app(BillingPayments::class)->invoice($actor, $key, []),
            fn () => app(ExpenseAdministration::class)->create($actor, $key, ['amount' => '1.00']),
            fn () => app(InventoryLedger::class)->correction($actor, $key, []),
            fn () => app(PurchaseRequestAdministration::class)->record($actor, ['supplier_id' => $key, 'reason' => 'Prueba', 'items' => []]),
            fn () => app(PurchaseFulfillment::class)->record($actor, ['purchase_request_id' => $key, 'reason' => 'Prueba', 'items' => []]),
        ];
        try {
            foreach ($commands as $command) {
                $start = hrtime(true);
                try {
                    $command();
                    $this->fail('Debe expirar el bloqueo.');
                } catch (ConflictHttpException $exception) {
                    $this->assertSame(409, $exception->getStatusCode());
                    $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
                }
                $this->assertLessThan(2000, (hrtime(true) - $start) / 1000000);
                $this->assertSame(0, DB::transactionLevel());
            }
        } finally {
            $other->rollBack();
        }
        foreach (['billing_operations', 'expense_operations', 'inventory_operations', 'purchasing_operations', 'purchase_fulfillment_operations', 'billing_invoices', 'expenses', 'inventory_corrections', 'purchase_requests', 'purchase_orders'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_statement_timeout_rolls_back_prior_write_and_restores_local_settings(): void
    {
        $this->prepareWorkshopSchema();
        $before = DB::selectOne("SELECT current_setting('lock_timeout') AS lock, current_setting('statement_timeout') AS statement");
        config(['operations.lock_timeout_ms' => 20, 'operations.statement_timeout_ms' => 50]);
        $calls = 0;
        try {
            app(BoundedTransaction::class)->run(function () use (&$calls): void {
                $calls++;
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                DB::select('SELECT pg_sleep(1)');
            });
            $this->fail('Debe cancelar la sentencia.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, $calls);
        }
        $this->assertSame(1, (int) DB::table('transport_assignment_guard')->value('version'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertEquals($before, DB::selectOne("SELECT current_setting('lock_timeout') AS lock, current_setting('statement_timeout') AS statement"));
        app(BoundedTransaction::class)->run(fn () => DB::table('transport_assignment_guard')->where('id', 1)->increment('version'));
        $this->assertSame(2, (int) DB::table('transport_assignment_guard')->value('version'));
        $this->assertEquals($before, DB::selectOne("SELECT current_setting('lock_timeout') AS lock, current_setting('statement_timeout') AS statement"));
    }

    public function test_timeout_after_inventory_receipt_rolls_back_and_same_key_can_confirm_once(): void
    {
        $this->prepareWorkshopSchema();
        $actor = $this->actor();
        $part = SparePart::create(['code' => 'F4', 'description' => 'Prueba', 'unit' => 'unidad']);
        $key = (string) Str::uuid();
        $payload = ['spare_part_id' => $part->id, 'kind' => 'initial', 'quantity' => '1', 'unit_snapshot' => 'unidad', 'reason' => 'Prueba', 'occurred_at' => now()->toIso8601String()];
        config(['operations.lock_timeout_ms' => 20, 'operations.statement_timeout_ms' => 50]);
        $interrupt = true;
        DB::listen(function ($query) use (&$interrupt): void {
            if ($interrupt && str_starts_with($query->sql, 'insert into "inventory_operations"')) {
                $interrupt = false;
                DB::select('SELECT pg_sleep(1)');
            }
        });
        $ledger = app(InventoryLedger::class);
        try {
            $ledger->correction($actor, $key, $payload);
            $this->fail('Debe revertir también el recibo insertado.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->assertDatabaseCount('inventory_corrections', 0);
        $this->assertDatabaseCount('inventory_operations', 0);
        $result = $ledger->correction($actor, $key, $payload);
        $this->assertSame($result, $ledger->correction($actor, $key, $payload));
        $this->assertDatabaseCount('inventory_corrections', 1);
        $this->assertDatabaseCount('inventory_operations', 1);
    }

    public function test_real_deadlock_rolls_back_all_writes_without_retry(): void
    {
        $this->prepareWorkshopSchema();
        $c = config('database.connections.pgsql_testing');
        $other = pg_connect('host='.$c['host'].' port='.$c['port'].' dbname=transporte_test user=transport_test', PGSQL_CONNECT_FORCE_NEW);
        $this->assertNotFalse($other);
        pg_query($other, 'BEGIN');
        pg_query($other, 'SELECT pg_advisory_xact_lock(998002)');
        config(['operations.lock_timeout_ms' => 3000, 'operations.statement_timeout_ms' => 5000]);
        $calls = 0;
        try {
            app(BoundedTransaction::class)->run(function () use ($other, &$calls): void {
                $calls++;
                DB::statement("SET LOCAL deadlock_timeout='50ms'");
                DB::select('SELECT pg_advisory_xact_lock(998001)');
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                $this->assertTrue(pg_send_query($other, 'SELECT pg_advisory_xact_lock(998001)'));
                try {
                    DB::select('SELECT pg_advisory_xact_lock(998002)');
                } catch (QueryException $exception) {
                    $this->assertSame('40P01', $exception->errorInfo[0]);
                    throw $exception;
                }
            });
            $this->fail('Debe detectar el ciclo real.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, $calls);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(1, (int) DB::table('transport_assignment_guard')->value('version'));
        } finally {
            while (pg_get_result($other) !== false) {
                // Drain the asynchronous lock request after the victim rolled back.
            }
            pg_query($other, 'ROLLBACK');
            pg_close($other);
        }
    }

    public function test_outer_transaction_is_rejected_before_work_or_configuration(): void
    {
        $this->expectException(LogicException::class);
        app(BoundedTransaction::class)->run(fn () => $this->fail('No debe ejecutar el comando.'));
    }
}
