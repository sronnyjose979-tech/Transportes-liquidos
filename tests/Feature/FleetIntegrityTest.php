<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Models\Compartment;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\Tanker;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;

class FleetIntegrityTest extends PostgresTestCase
{
    private function tanker(bool $partitioned = false, int $count = 1): FleetAsset
    {
        $asset = FleetAsset::create(['code' => 'T-'.Str::uuid(), 'type' => 'tanker']);
        Tanker::create(['fleet_asset_id' => $asset->id, 'has_physical_partitions' => $partitioned]);
        for ($index = 1; $index <= $count; $index++) {
            Compartment::create(['tanker_id' => $asset->id, 'code' => 'C-'.$index, 'capacity_liters' => '1250.25', 'is_active' => true]);
        }

        return $asset;
    }

    private function validateDeferred(): void
    {
        DB::statement('SET CONSTRAINTS fleet_asset_integrity, tractor_unit_integrity, tanker_integrity, compartment_integrity IMMEDIATE');
        DB::statement('SET CONSTRAINTS fleet_asset_integrity, tractor_unit_integrity, tanker_integrity, compartment_integrity DEFERRED');
    }

    private function rejects(callable $operation, string $state = '23514'): void
    {
        try {
            DB::transaction(function () use ($operation) {
                $operation();
                $this->validateDeferred();
            });
            $this->fail('PostgreSQL debía rechazar la operación.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }

    public function test_complete_aggregates_have_shared_uuid_keys_and_exact_decimal_capacity(): void
    {
        $tractor = FleetAsset::create(['code' => 'TRACTOR', 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $tractor->id]);
        $tanker = $this->tanker(true, 2);
        $this->validateDeferred();

        $this->assertSame('available', $tractor->refresh()->service_status);
        $this->assertTrue($tractor->tractorUnit->fleetAsset->is($tractor));
        $this->assertTrue($tanker->tanker->fleetAsset->is($tanker));
        $this->assertSame(2, $tanker->tanker->compartments->count());
        $compartment = $tanker->tanker->compartments->first();
        $this->assertSame('1250.25', $compartment->capacity_liters);
        $this->assertTrue($compartment->tanker->is($tanker->tanker));
        $this->assertTrue($compartment->is_active);
    }

    public function test_total_exclusive_specialization_and_type_are_enforced_for_sql_updates(): void
    {
        $this->rejects(fn () => FleetAsset::create(['code' => 'INCOMPLETE', 'type' => 'tractor_unit']));
        $this->assertSame(0, FleetAsset::count());
        $asset = $this->tanker();
        $this->validateDeferred();
        $this->rejects(fn () => TractorUnit::create(['fleet_asset_id' => $asset->id]));
        $this->rejects(fn () => DB::table('fleet_assets')->where('id', $asset->id)->update(['type' => 'tractor_unit']));
        $this->rejects(fn () => DB::table('compartments')->where('tanker_id', $asset->id)->delete());
        $this->rejects(function () use ($asset) {
            DB::table('compartments')->where('tanker_id', $asset->id)->delete();
            DB::table('tankers')->where('fleet_asset_id', $asset->id)->delete();
        });
        $this->assertSame('tanker', $asset->refresh()->type);
        $this->assertSame(1, $asset->tanker->compartments()->count());
    }

    public function test_tanker_cardinality_is_checked_at_creation_and_updates(): void
    {
        $this->rejects(fn () => $this->tanker(true, 0));
        $this->rejects(fn () => $this->tanker(false, 2));
        $asset = $this->tanker(true, 2);
        $this->validateDeferred();
        $this->rejects(fn () => $asset->tanker->update(['has_physical_partitions' => false]));
        $this->rejects(fn () => DB::table('compartments')->where('tanker_id', $asset->id)->delete());
        DB::transaction(function () use ($asset) {
            $asset->tanker->compartments()->orderBy('id')->first()->delete();
            $asset->tanker->update(['has_physical_partitions' => false]);
            $this->validateDeferred();
        });
        $this->assertSame(1, $asset->tanker->compartments()->count());
    }

    public function test_capacity_and_codes_are_protected_by_database_constraints(): void
    {
        $column = DB::selectOne("SELECT data_type, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'compartments' AND column_name = 'capacity_liters'");
        $this->assertSame('numeric', $column->data_type);
        $this->assertSame(12, $column->numeric_precision);
        $this->assertSame(2, $column->numeric_scale);
        $asset = $this->tanker(true);
        $other = $this->tanker();
        $this->validateDeferred();
        $this->rejects(fn () => $other->update(['code' => $asset->code]), '23505');
        $this->rejects(fn () => $other->update(['code' => '   ']));
        $this->rejects(fn () => $other->update(['service_status' => 'unknown']));
        $compartment = $asset->tanker->compartments()->first();
        foreach (['0.00', '-0.01', 'NaN'] as $capacity) {
            $this->rejects(fn () => DB::table('compartments')->where('id', $compartment->id)->update(['capacity_liters' => $capacity]));
        }
        $this->rejects(fn () => DB::table('compartments')->where('id', $compartment->id)->update(['capacity_liters' => '10000000000.00']), '22003');
        $this->rejects(fn () => DB::table('compartments')->insert(['id' => Str::uuid(), 'tanker_id' => $asset->id, 'code' => $compartment->code, 'capacity_liters' => '10.00', 'is_active' => true]), '23505');
        $this->rejects(fn () => DB::table('compartments')->where('id', $compartment->id)->update(['is_active' => null]), '23502');
        $this->assertSame('1250.25', $compartment->refresh()->capacity_liters);
        $this->assertSame(2, Compartment::where('code', 'C-1')->count());
        $other->refresh()->update(['code' => 'EDITED']);
        $this->validateDeferred();
        $this->assertSame('EDITED', $other->refresh()->code);
    }

    public function test_unknown_parent_and_parent_deletion_are_rejected(): void
    {
        $this->rejects(fn () => TractorUnit::create(['fleet_asset_id' => Str::uuid()]), '23503');
        $this->rejects(fn () => Compartment::create(['tanker_id' => Str::uuid(), 'code' => 'C', 'capacity_liters' => '1.00', 'is_active' => true]), '23503');
        $asset = $this->tanker();
        $this->validateDeferred();
        $this->rejects(fn () => $asset->delete(), '23503');
    }

    public function test_truncate_cannot_bypass_mandatory_children(): void
    {
        $asset = $this->tanker();
        $this->validateDeferred();
        // Load history now references compartments; PostgreSQL rejects before row triggers.
        $this->rejects(fn () => DB::statement('TRUNCATE compartments'), '0A000');
        $this->rejects(fn () => DB::statement('TRUNCATE tractor_units'));
        $this->rejects(fn () => DB::statement('TRUNCATE tankers CASCADE'));
        $this->assertSame(1, $asset->tanker->compartments()->count());
    }

    private function independentConnection(): PDO
    {
        $config = config('database.connections.pgsql_testing');

        return new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function test_invalid_aggregates_fail_at_real_commit_and_leave_no_rows(): void
    {
        $connection = $this->independentConnection();
        try {
            foreach (['tractor_unit', 'tanker'] as $type) {
                $id = (string) Str::uuid();
                $connection->beginTransaction();
                $statement = $connection->prepare('INSERT INTO fleet_assets (id, code, type) VALUES (?, ?, ?)');
                $statement->execute([$id, 'COMMIT-'.$id, $type]);
                if ($type === 'tanker') {
                    $statement = $connection->prepare('INSERT INTO tankers (fleet_asset_id, has_physical_partitions) VALUES (?, true)');
                    $statement->execute([$id]);
                }
                try {
                    // No SET CONSTRAINTS: test the deferred triggers at COMMIT itself.
                    $connection->commit();
                    $this->fail('Un agregado incompleto no puede confirmarse.');
                } catch (PDOException $exception) {
                    $this->assertSame('23514', $exception->getCode());
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                }
                $this->assertDatabaseMissing('fleet_assets', ['id' => $id]);
                $this->assertDatabaseMissing('tankers', ['fleet_asset_id' => $id]);
            }
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
    }

    public function test_concurrent_child_writers_serialize_and_obsolete_snapshots_abort(): void
    {
        $asset = $this->tanker(true, 2);
        DB::commit(); // Publish synthetic data to the independent sessions.
        $first = $this->independentConnection();
        $second = $this->independentConnection();
        $ids = DB::table('compartments')->where('tanker_id', $asset->id)->orderBy('id')->pluck('id');
        try {
            $first->beginTransaction();
            $first->exec("UPDATE compartments SET capacity_liters = 2000 WHERE id = '{$ids[0]}'");
            $second->beginTransaction();
            $second->exec("SET LOCAL lock_timeout = '100ms'");
            try {
                $second->exec("DELETE FROM compartments WHERE id = '{$ids[1]}'");
                $this->fail('Las escrituras del mismo agregado deben serializarse.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }
            $second->rollBack();
            $first->commit();

            $second->beginTransaction();
            $second->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $second->query("SELECT count(*) FROM compartments WHERE tanker_id = '{$asset->id}'")->fetchColumn();
            $first->beginTransaction();
            $first->exec("DELETE FROM compartments WHERE id = '{$ids[0]}'");
            $first->commit();
            try {
                $second->exec("DELETE FROM compartments WHERE id = '{$ids[1]}'");
                $second->commit();
                $this->fail('Un snapshot obsoleto no puede eliminar el último compartimento.');
            } catch (PDOException $exception) {
                $this->assertSame('40001', $exception->getCode());
                if ($second->inTransaction()) {
                    $second->rollBack();
                }
            }
            $this->assertSame(1, DB::table('compartments')->where('tanker_id', $asset->id)->count());
            $second->beginTransaction(); // Default READ COMMITTED sees the first writer's commit.
            try {
                $second->exec("DELETE FROM compartments WHERE id = '{$ids[1]}'");
                $second->commit();
                $this->fail('Eliminar el último compartimento debe fallar al confirmar.');
            } catch (PDOException $exception) {
                $this->assertSame('23514', $exception->getCode());
                if ($second->inTransaction()) {
                    $second->rollBack();
                }
            }
            $this->assertSame(1, DB::table('compartments')->where('tanker_id', $asset->id)->count());
        } finally {
            foreach ([$first, $second] as $connection) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
            }
            DB::transaction(function () use ($asset) {
                DB::table('compartments')->where('tanker_id', $asset->id)->delete();
                DB::table('tankers')->where('fleet_asset_id', $asset->id)->delete();
                DB::table('fleet_assets')->where('id', $asset->id)->delete();
            });
            DB::beginTransaction();
        }
    }
}
