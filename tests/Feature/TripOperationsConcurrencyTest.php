<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Application\TripOperations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TripOperationsConcurrencyTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_two_loads_for_same_trip_wait_then_cannot_register_second_operation(): void
    {
        $this->withOperationalSchema(function (PDO $other): void {
            $trip = $this->trip($this->request());
            $this->ensureAssignments($trip);
            $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->first();
            $compartment = $assignment->fleetAsset->tanker->compartments->first();
            $site = $trip->transportRequest->route->origin_site_id;
            DB::commit();
            DB::beginTransaction();
            app(TripOperations::class)->load($trip->registeredBy, $trip->id, $site, [$compartment->id => '500.25'], CarbonImmutable::now(), 'Primera carga');
            $sql = "INSERT INTO trip_loads(id,trip_id,tanker_assignment_id,operation_site_id,recorded_by_id,reason,occurred_at) VALUES (?,?,?,?,?,'Carga rival',clock_timestamp())";
            $values = [(string) Str::uuid(), $trip->id, $assignment->id, $site, $trip->registered_by_id];
            $other->beginTransaction();
            $other->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $other->prepare($sql)->execute($values);
                $this->fail('Carga concurrente debía esperar.');
            } catch (PDOException $e) {
                $this->assertSame('55P03', $e->getCode());
                $other->rollBack();
            }
            $this->assertSame(0, (int) $other->query('SELECT count(*) FROM trip_loads')->fetchColumn());
            DB::commit();
            $other->beginTransaction();
            try {
                $other->prepare($sql)->execute($values);
                $this->fail('Carga única por viaje.');
            } catch (PDOException $e) {
                $this->assertSame('23505', $e->getCode());
                $other->rollBack();
            }
            $this->assertSame(1, (int) $other->query('SELECT count(*) FROM trip_loads')->fetchColumn());
            $this->assertSame(1, (int) $other->query('SELECT count(*) FROM load_items')->fetchColumn());
        });
    }

    public function test_concurrent_odometer_corrections_cannot_branch_or_overwrite_original(): void
    {
        $this->withOperationalSchema(function (PDO $other): void {
            $trip = $this->trip($this->request());
            $this->ensureAssignments($trip);
            $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tractor_unit')->first();
            $service = app(TripOperations::class);
            $original = $service->reading($trip->registeredBy, $trip->id, $assignment->id, 'initial', '100.25', CarbonImmutable::now(), 'Original');
            DB::commit();
            DB::beginTransaction();
            $corrected = $service->correctReading($trip->registeredBy, $original->id, '101.50', CarbonImmutable::now(), 'Primera corrección');
            $sql = "INSERT INTO trip_odometer_readings(id,trip_id,tractor_assignment_id,reading_type,reading_value,recorded_by_id,reason,occurred_at,previous_reading_id,revision) VALUES (?,?,?,'initial',200,?,'Corrección rival',clock_timestamp(),?,2)";
            $values = [(string) Str::uuid(), $trip->id, $assignment->id, $trip->registered_by_id, $original->id];
            $other->beginTransaction();
            $other->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $other->prepare($sql)->execute($values);
                $this->fail('Corrección debía esperar.');
            } catch (PDOException $e) {
                $this->assertSame('55P03', $e->getCode());
                $other->rollBack();
            }
            DB::commit();
            $other->beginTransaction();
            try {
                $other->prepare($sql)->execute($values);
                $this->fail('No crear rama de corrección.');
            } catch (PDOException $e) {
                $this->assertSame('23505', $e->getCode());
                $other->rollBack();
            }
            $this->assertSame('100.25', $original->fresh()->reading_value);
            $this->assertSame('101.50', $corrected->fresh()->reading_value);
            $this->assertSame(2, (int) $other->query('SELECT count(*) FROM trip_odometer_readings')->fetchColumn());
        });
    }

    public function test_concurrent_completion_waits_and_cannot_overwrite_confirmed_audit(): void
    {
        $this->withOperationalSchema(function (PDO $other): void {
            $trip = $this->trip($this->request());
            $this->ensureAssignments($trip);
            $service = app(TripOperations::class);
            $trip = $service->start($trip->registeredBy, $trip->id, 'Inicio');
            $this->operationalRecords($trip);
            DB::commit();
            DB::beginTransaction();
            $finished = $service->finish($trip->registeredBy, $trip->id, 'Primera confirmación');
            $other->beginTransaction();
            $other->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $other->query('SELECT pg_advisory_xact_lock(873210)');
                $this->fail('Finalización concurrente debe esperar.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
                $other->rollBack();
            }
            $this->assertSame('in_progress', $other->query("SELECT execution_status FROM trips WHERE id='".$trip->id."'")->fetchColumn());
            DB::commit();
            // Rival retries its conditional transition after the first commit.
            $statement = $other->prepare("UPDATE trips SET execution_status='completed',completed_by_id=?,officially_completed_at=clock_timestamp(),version=version+1 WHERE id=? AND execution_status='in_progress'");
            $statement->execute([$trip->registered_by_id, $trip->id]);
            $this->assertSame(0, $statement->rowCount());
            $this->assertSame('completed', $finished->fresh()->execution_status);
            $this->assertSame(2, $finished->changes()->count());
            $this->assertSame(1, $finished->driverAssignments()->current()->count());
            $this->assertSame(2, $finished->assetAssignments()->current()->count());
        });
    }

    private function withOperationalSchema(callable $test): void
    {
        $config = config('database.connections.pgsql_testing');
        $other = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'assignment_concurrency_'.str_replace('-', '', (string) Str::uuid());
        $tables = ['users', 'roles', 'user_roles', 'employees', 'customers', 'liquid_products', 'operation_sites', 'fleet_assets', 'tractor_units', 'tankers', 'compartments',
            'routes', 'transport_requests', 'trips', 'transport_authorization_requests', 'transport_authorization_decisions', 'transport_changes', 'trip_product_reviews', 'trip_events', 'trip_warnings', 'trip_warning_resolutions',
            'transport_assignment_guard', 'transport_assignment_operations', 'trip_asset_assignments', 'trip_asset_releases', 'driver_assignments', 'driver_releases', 'transport_assignment_conflicts', 'transport_authorization_applications', 'trip_loads', 'load_items', 'trip_unloads', 'unload_items', 'trip_odometer_readings'];
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
