<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class WorkshopCoreConcurrencyTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_writers_wait_and_duplicate_receipt_rolls_back_second_fact(): void
    {
        $this->withIsolatedCore(function (PDO $other): void {
            [$asset, $actor] = $this->fixture();
            $key = (string) Str::uuid();
            DB::commit();
            DB::beginTransaction();
            $this->insertReport(DB::connection()->getPdo(), $asset, $actor, $key);
            $other->beginTransaction();
            $other->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $this->insertReport($other, $asset, $actor, $key);
                $this->fail('La segunda escritura debe esperar al coordinador.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
                $other->rollBack();
            }
            $this->assertSame(0, (int) $other->query('SELECT count(*) FROM workshop_reports')->fetchColumn());
            DB::commit();
            $other->beginTransaction();
            try {
                $this->insertReport($other, $asset, $actor, $key);
                $other->commit();
                $this->fail('El recibo repetido debe impedir un segundo efecto.');
            } catch (PDOException $exception) {
                $this->assertSame('23505', $exception->getCode());
                $other->rollBack();
            }
            $this->assertSame(1, (int) $other->query('SELECT count(*) FROM workshop_reports')->fetchColumn());
            $this->assertSame(1, (int) $other->query('SELECT count(*) FROM workshop_operations')->fetchColumn());
        });
    }

    public function test_repeatable_read_snapshot_aborts_after_another_core_writer(): void
    {
        $this->withIsolatedCore(function (PDO $other): void {
            [$asset, $actor] = $this->fixture();
            DB::commit();
            $other->beginTransaction();
            $other->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->assertSame(0, (int) $other->query('SELECT count(*) FROM workshop_reports')->fetchColumn());
            DB::beginTransaction();
            $this->insertReport(DB::connection()->getPdo(), $asset, $actor, (string) Str::uuid());
            DB::commit();
            try {
                $this->insertReport($other, $asset, $actor, (string) Str::uuid());
                $other->commit();
                $this->fail('El guard modificado debe rechazar el snapshot obsoleto.');
            } catch (PDOException $exception) {
                $this->assertSame('40001', $exception->getCode());
                $other->rollBack();
            }
            $this->assertSame(1, (int) $other->query('SELECT count(*) FROM workshop_reports')->fetchColumn());
        });
    }

    private function fixture(): array
    {
        $actor = $this->actor();
        $asset = FleetAsset::create(['code' => 'CONCURRENT-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);

        return [$asset->id, $actor->id];
    }

    private function insertReport(PDO $connection, string $asset, int $actor, string $key): void
    {
        $id = (string) Str::uuid();
        $connection->prepare("INSERT INTO workshop_reports(id,fleet_asset_id,description,recorded_by_id,occurred_at,reason,root_record_id,revision) VALUES (?,?,?,?,'2026-10-12T12:00:00Z',?,?,1)")
            ->execute([$id, $asset, 'Reporte concurrente sintético', $actor, 'Prueba aislada', $id]);
        $connection->prepare("INSERT INTO workshop_operations(id,report_id,operation_type,actor_id,occurred_at,reason,after_values,request_key,request_fingerprint,idempotency_scope,confirmed_result) VALUES (?,?,'report_create',?,'2026-10-12T12:00:00Z',?,'{}',?,?,?,'{}')")
            ->execute([(string) Str::uuid(), $id, $actor, 'Prueba aislada', $key, str_repeat('a', 64), 'report_create']);
    }

    private function withIsolatedCore(callable $test): void
    {
        $config = config('database.connections.pgsql_testing');
        $other = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'workshop_concurrency_'.str_replace('-', '', (string) Str::uuid());
        $tables = $other->query("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename<>'migrations' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        $other->exec('CREATE SCHEMA '.$schema);
        try {
            $other->exec('SET search_path TO '.$schema.',public');
            foreach ($tables as $table) {
                $other->exec('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING ALL)');
            }
            foreach ($tables as $table) {
                foreach ($other->query("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='public.$table'::regclass AND contype='f'")->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
                    $other->exec('ALTER TABLE '.$schema.'.'.$table.' ADD CONSTRAINT '.$constraint['conname'].' '.str_replace('public.', $schema.'.', $constraint['definition']));
                }
                foreach ($other->query("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgrelid='public.$table'::regclass AND NOT tgisinternal")->fetchAll(PDO::FETCH_COLUMN) as $trigger) {
                    $other->exec(str_replace(' ON public.'.$table.' ', ' ON '.$schema.'.'.$table.' ', $trigger));
                }
            }
            $other->exec('INSERT INTO transport_assignment_guard VALUES (1,1)');
            DB::statement('SET search_path TO '.$schema.',public');
            $test($other);
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::statement('SET search_path TO public');
            $other->exec('SET search_path TO public');
            $other->exec('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
}
