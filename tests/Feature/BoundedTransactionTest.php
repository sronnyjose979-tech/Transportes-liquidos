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
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use PDO;
use PDOException;
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

    public function test_deferred_commit_errors_are_safe_atomic_and_allow_same_key_replay(): void
    {
        $this->prepareWorkshopSchema();
        $schema = DB::selectOne('SELECT current_schema() AS name')->name;
        $observer = $this->rival();
        $observer->exec('SET search_path TO '.$schema.',public');
        $actor = $this->actor();
        $part = SparePart::create(['code' => 'COMMIT', 'description' => 'Prueba', 'unit' => 'unidad']);
        $payload = ['spare_part_id' => $part->id, 'kind' => 'initial', 'quantity' => '1', 'unit_snapshot' => 'unidad', 'reason' => 'Prueba', 'occurred_at' => now()->toIso8601String()];
        $logs = new TestHandler;
        Log::swap(new Logger(new Monolog('commit-test', [$logs])));
        config(['app.debug' => true, 'operations.lock_timeout_ms' => 30, 'operations.statement_timeout_ms' => 100]);
        $baseline = DB::selectOne("SELECT current_setting('lock_timeout') AS lock, current_setting('statement_timeout') AS statement");
        // Raised states exercise the real PDO::commit path; cancel and lock cases
        // below additionally exercise actual server timeouts during deferred work.
        $confirmed = 0;
        foreach (['40001', '40P01', '55P03', '57014', 'cancel', 'lock', '23514', 'disconnect'] as $case) {
            $key = (string) Str::uuid();
            $body = match ($case) {
                'cancel' => 'PERFORM pg_cancel_backend(pg_backend_pid()); PERFORM pg_sleep(1);',
                'lock' => 'PERFORM pg_advisory_xact_lock(998010);',
                'disconnect' => 'PERFORM pg_terminate_backend(pg_backend_pid());',
                default => "RAISE EXCEPTION 'synthetic-secret internal-host' USING ERRCODE = '$case';",
            };
            DB::unprepared('CREATE OR REPLACE FUNCTION commit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '.$body.' RETURN NEW; END $$');
            DB::unprepared('CREATE CONSTRAINT TRIGGER commit_failure AFTER INSERT ON inventory_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commit_failure()');
            if ($case === 'lock') {
                $observer->beginTransaction();
                $observer->query('SELECT pg_advisory_xact_lock(998010)');
            }
            $pdo = DB::connection()->getPdo();
            $calls = 0;
            $url = '/api/inventory/__commit-'.$case;
            Route::post($url, function () use ($actor, $key, $payload, &$calls) {
                $calls++;

                return app(InventoryLedger::class)->correction($actor, $key, $payload);
            });
            $response = $this->postJson($url);
            $this->assertSame(in_array($case, ['23514', 'disconnect'], true) ? 503 : 409, $response->status(), $case);
            $this->assertSame(1, $calls);
            $this->assertStringContainsString('clave', $response->getContent());
            foreach (['synthetic-secret', 'internal-host', 'SQLSTATE'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
                $this->assertStringNotContainsString($secret, json_encode($logs->getRecords(), JSON_THROW_ON_ERROR));
            }
            if ($case === 'lock') {
                $observer->rollBack();
            }
            $this->assertSame(0, DB::transactionLevel());
            if ($case !== 'disconnect') {
                $this->assertFalse($pdo->inTransaction());
                $this->assertEquals($baseline, $pdo->query("SELECT current_setting('lock_timeout') AS lock, current_setting('statement_timeout') AS statement")->fetchObject());
            }
            $this->assertSame(1, (int) $observer->query('SELECT CASE WHEN pg_try_advisory_xact_lock(873210) THEN 1 ELSE 0 END')->fetchColumn());
            $this->assertSame(0, (int) $observer->query("SELECT count(*) FROM inventory_operations WHERE request_key = '$key'")->fetchColumn());
            $this->assertSame($confirmed, (int) $observer->query('SELECT count(*) FROM inventory_corrections')->fetchColumn());
            $this->assertSame(0, (int) $observer->query('SELECT count(*) FROM inventory_movements')->fetchColumn());
            DB::statement('SET search_path TO '.$schema.',public');
            $this->assertEquals($baseline, DB::selectOne("SELECT current_setting('lock_timeout') AS lock, current_setting('statement_timeout') AS statement"));
            DB::unprepared('DROP TRIGGER commit_failure ON inventory_operations');
            $ledger = app(InventoryLedger::class);
            $result = $ledger->correction($actor, $key, $payload);
            $this->assertSame($result, $ledger->correction($actor, $key, $payload));
            $this->assertSame(1, (int) $observer->query("SELECT count(*) FROM inventory_operations WHERE request_key = '$key'")->fetchColumn());
            $confirmed++;
            $this->assertSame($confirmed, (int) $observer->query('SELECT count(*) FROM inventory_corrections')->fetchColumn());
            $this->assertSame(0, (int) $observer->query('SELECT count(*) FROM inventory_movements')->fetchColumn());
        }
        $warnings = array_values(array_filter($logs->getRecords(), fn ($r) => $r['message'] === 'database.operation_failed'));
        $this->assertCount(2, $warnings);
        $this->assertSame('23514', $warnings[0]['context']['sqlstate']);
        $this->assertContains($warnings[1]['context']['sqlstate'], ['08006', 'HY000']);
    }

    public function test_lost_commit_acknowledgement_discards_connection_without_repeating_confirmed_effects(): void
    {
        $this->prepareWorkshopSchema();
        $schema = DB::selectOne('SELECT current_schema() AS name')->name;
        $actor = $this->actor();
        $part = SparePart::create(['code' => 'ACK', 'description' => 'Prueba', 'unit' => 'unidad']);
        $key = (string) Str::uuid();
        $payload = ['spare_part_id' => $part->id, 'kind' => 'initial', 'quantity' => '1', 'unit_snapshot' => 'unidad', 'reason' => 'Prueba', 'occurred_at' => now()->toIso8601String()];
        $c = config('database.connections.pgsql_testing');
        // Fault injection only at the acknowledgement boundary: PostgreSQL really
        // commits, but the client receives a connection failure instead of success.
        $pdo = new class('pgsql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'], $c['username'], $c['password']) extends PDO
        {
            public int $commits = 0;

            public function commit(): bool
            {
                $this->commits++;
                parent::commit();
                $failure = new PDOException('synthetic-secret lost acknowledgement');
                $failure->errorInfo = ['08006', 7, $failure->getMessage()];
                throw $failure;
            }
        };
        $pdo->exec('SET search_path TO '.$schema.',public');
        DB::connection()->setPdo($pdo);
        Route::post('/api/inventory/__lost-ack', fn () => app(InventoryLedger::class)->correction($actor, $key, $payload));
        config(['app.debug' => true]);
        $logs = new TestHandler;
        Log::swap(new Logger(new Monolog('commit-test', [$logs])));
        $response = $this->postJson('/api/inventory/__lost-ack')->assertStatus(503);
        $this->assertStringContainsString('conserve la clave', $response->getContent());
        $this->assertStringNotContainsString('synthetic-secret', $response->getContent());
        $this->assertStringNotContainsString('synthetic-secret', json_encode($logs->getRecords(), JSON_THROW_ON_ERROR));
        $this->assertSame(1, $pdo->commits);
        $this->assertFalse($pdo->inTransaction());
        $this->assertNotSame($pdo, DB::connection()->getPdo());
        $observer = $this->rival();
        $this->assertSame(1, (int) $observer->query('SELECT count(*) FROM '.$schema.'.inventory_operations')->fetchColumn());
        $this->assertSame(1, (int) $observer->query('SELECT count(*) FROM '.$schema.'.inventory_corrections')->fetchColumn());
        DB::statement('SET search_path TO '.$schema.',public');
        $ledger = app(InventoryLedger::class);
        $first = $ledger->correction($actor, $key, $payload);
        $this->assertSame($first, $ledger->correction($actor, $key, $payload));
        $this->assertDatabaseCount('inventory_operations', 1);
        $this->assertDatabaseCount('inventory_corrections', 1);
    }

    public function test_cleanup_failure_discards_session_and_preserves_original_commit_conflict(): void
    {
        $this->prepareWorkshopSchema();
        $schema = DB::selectOne('SELECT current_schema() AS name')->name;
        $c = config('database.connections.pgsql_testing');
        $pdo = new class('pgsql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'], $c['username'], $c['password']) extends PDO
        {
            public function commit(): bool
            {
                $failure = new PDOException('synthetic-secret');
                $failure->errorInfo = ['55P03', 7, $failure->getMessage()];
                throw $failure;
            }

            public function rollBack(): bool
            {
                parent::rollBack();
                throw new PDOException('synthetic-cleanup-secret');
            }
        };
        $pdo->exec('SET search_path TO '.$schema.',public');
        DB::connection()->setPdo($pdo);
        $calls = 0;
        $afterCommit = 0;
        try {
            app(BoundedTransaction::class)->run(function () use (&$calls, &$afterCommit): void {
                $calls++;
                DB::afterCommit(function () use (&$afterCommit): void {
                    $afterCommit++;
                });
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
            });
            $this->fail('Debe conservar el conflicto original.');
        } catch (ConflictHttpException $failure) {
            $this->assertSame(409, $failure->getStatusCode());
            $this->assertStringNotContainsString('synthetic', $failure->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertNotSame($pdo, DB::connection()->getPdo());
        $this->assertSame(0, DB::transactionLevel());
        $observer = $this->rival();
        $this->assertSame(1, (int) $observer->query('SELECT version FROM '.$schema.'.transport_assignment_guard')->fetchColumn());
        app(BoundedTransaction::class)->run(fn () => DB::select('SELECT 1'));
        $this->assertSame(0, $afterCommit);
    }

    public function test_outer_transaction_is_rejected_before_work_or_configuration(): void
    {
        $this->expectException(LogicException::class);
        app(BoundedTransaction::class)->run(fn () => $this->fail('No debe ejecutar el comando.'));
    }
}
