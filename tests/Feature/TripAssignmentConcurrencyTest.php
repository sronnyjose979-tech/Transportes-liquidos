<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TripAssignmentConcurrencyTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_concurrent_assignments_wait_and_cannot_bypass_conflicts_for_each_resource(): void
    {
        $this->withIsolatedAssignments(function (PDO $other): void {
            $service = app(AssignmentAdministration::class);
            foreach ($this->assignmentResources() as $kind => $resource) {
                $first = $this->trip($this->request());
                $second = $this->trip($this->request());
                $actor = $first->registeredBy;
                DB::commit();
                DB::beginTransaction();
                $assignment = $service->assign($actor, $first->id, $kind, $resource->id, 'Primera concurrente');
                $other->beginTransaction();
                $other->exec("SET LOCAL lock_timeout='100ms'");
                try {
                    $this->insertAssignment($other, $second->id, $second->registered_by_id, $kind, $resource->id);
                    $this->fail('La asignación concurrente debía esperar.');
                } catch (PDOException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                    $other->rollBack();
                }
                $table = $kind === 'driver' ? 'driver_assignments' : 'trip_asset_assignments';
                $this->assertSame(0, (int) $other->query("SELECT count(*) FROM $table WHERE trip_id='$first->id'")->fetchColumn());
                DB::commit();
                $other->beginTransaction();
                try {
                    $this->insertAssignment($other, $second->id, $second->registered_by_id, $kind, $resource->id);
                    $other->commit();
                    $this->fail('No puede aceptar un conflicto con el conjunto previo vacío.');
                } catch (PDOException $exception) {
                    $this->assertSame('23514', $exception->getCode());
                    $other->rollBack();
                }
                $this->assertSame(1, (int) $other->query("SELECT count(*) FROM $table WHERE trip_id='$first->id'")->fetchColumn());
                $this->assertSame(0, (int) $other->query("SELECT count(*) FROM $table WHERE trip_id='$second->id'")->fetchColumn());
                $service->release($actor, $first->id, $kind, 'Liberación real');
                $other->beginTransaction();
                $this->insertAssignment($other, $second->id, $second->registered_by_id, $kind, $resource->id);
                $other->commit();
                $this->assertSame(1, (int) $other->query("SELECT count(*) FROM $table WHERE trip_id='$second->id'")->fetchColumn());
                $this->assertNotNull($assignment->fresh()->release);
                DB::beginTransaction();
            }
        });
    }

    public function test_repeatable_read_stale_snapshot_fails_instead_of_ignoring_new_assignment(): void
    {
        $this->withIsolatedAssignments(function (PDO $other): void {
            $resource = $this->assignmentResources()['driver'];
            $first = $this->trip($this->request());
            $second = $this->trip($this->request());
            DB::commit();
            $other->beginTransaction();
            $other->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->assertSame(0, (int) $other->query('SELECT count(*) FROM driver_assignments')->fetchColumn());
            app(AssignmentAdministration::class)->assign($first->registeredBy, $first->id, 'driver', $resource->id, 'Confirmada después de snapshot');
            try {
                $this->insertAssignment($other, $second->id, $second->registered_by_id, 'driver', $resource->id);
                $other->commit();
                $this->fail('Snapshot obsoleto no debe confirmar.');
            } catch (PDOException $exception) {
                $this->assertSame('40001', $exception->getCode());
                $other->rollBack();
            }
            $this->assertSame(1, (int) $other->query('SELECT count(*) FROM driver_assignments')->fetchColumn());
        });
    }

    private function insertAssignment(PDO $connection, string $tripId, int $actorId, string $kind, string $resourceId): void
    {
        $operation = (string) Str::uuid();
        $payload = json_encode(['action' => 'assign', 'resource_kind' => $kind, 'resource_id' => $resourceId, 'previous_assignment_id' => null, 'conflicts' => []]);
        $connection->prepare('INSERT INTO transport_assignment_operations(id,trip_id,action,resource_kind,payload,target_version,actor_id,reason) VALUES (?,?,?,?,?::jsonb,1,?,?)')
            ->execute([$operation, $tripId, 'assign', $kind, $payload, $actorId, 'SQL concurrente sintético']);
        if ($kind === 'driver') {
            $connection->prepare('INSERT INTO driver_assignments(id,trip_id,employee_id,operation_id) VALUES (?,?,?,?)')
                ->execute([(string) Str::uuid(), $tripId, $resourceId, $operation]);
        } else {
            $connection->prepare('INSERT INTO trip_asset_assignments(id,trip_id,fleet_asset_id,operation_id,asset_type) VALUES (?,?,?,?,?)')
                ->execute([(string) Str::uuid(), $tripId, $resourceId, $operation, $kind]);
        }
    }

    private function withIsolatedAssignments(callable $test): void
    {
        $config = config('database.connections.pgsql_testing');
        $other = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'assignment_concurrency_'.str_replace('-', '', (string) Str::uuid());
        $tables = ['users', 'roles', 'user_roles', 'employees', 'customers', 'liquid_products', 'operation_sites', 'fleet_assets', 'tractor_units', 'tankers', 'compartments',
            'routes', 'transport_requests', 'trips', 'transport_authorization_requests', 'transport_authorization_decisions', 'transport_changes', 'trip_product_reviews', 'trip_events', 'trip_warnings', 'trip_warning_resolutions',
            'transport_assignment_guard', 'transport_assignment_operations', 'trip_asset_assignments', 'trip_asset_releases', 'driver_assignments', 'driver_releases', 'transport_assignment_conflicts', 'transport_authorization_applications'];
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
