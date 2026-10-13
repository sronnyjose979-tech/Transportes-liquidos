<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use App\Modules\Workshop\Application\WorkshopAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopAvailabilityConcurrencyTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $this->prepareWorkshopSchema();
        $actor = $this->actor();
        $asset = app(FleetAdministration::class)->save($actor, 'tractor_unit', ['code' => 'RACE-'.Str::uuid()]);
        DB::beginTransaction();
        $trip = $this->trip($this->request($actor));
        $this->checkConstraints();
        DB::commit();
        $config = config('database.connections.pgsql_testing');
        $other = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET search_path TO '.$this->workshopSchema.',public');

        return [$actor, $asset, $trip, $other];
    }

    private function insertAssignment(PDO $connection, string $trip, string $asset, int $actor): void
    {
        $operation = (string) Str::uuid();
        $payload = json_encode(['action' => 'assign', 'resource_kind' => 'tractor_unit', 'resource_id' => $asset, 'previous_assignment_id' => null, 'conflicts' => []], JSON_THROW_ON_ERROR);
        $connection->prepare("INSERT INTO transport_assignment_operations(id,trip_id,action,resource_kind,payload,target_version,actor_id,reason) VALUES (?,?,'assign','tractor_unit',?::jsonb,1,?,'Prueba concurrente')")
            ->execute([$operation, $trip, $payload, $actor]);
        $connection->prepare("INSERT INTO trip_asset_assignments(id,trip_id,fleet_asset_id,operation_id,asset_type) VALUES (?,?,?,?,'tractor_unit')")
            ->execute([(string) Str::uuid(), $trip, $asset, $operation]);
    }

    private function insertAdmission(PDO $connection, string $asset, int $actor): void
    {
        $admission = (string) Str::uuid();
        $cause = (string) Str::uuid();
        $connection->prepare("INSERT INTO workshop_admissions(id,fleet_asset_id,kind,admitted_at,admitted_by_id,reason) VALUES (?,?,'corrective','2026-10-12T12:00:00Z',?,'Prueba concurrente')")
            ->execute([$admission, $asset, $actor]);
        $connection->prepare("INSERT INTO fleet_operating_impediments(id,fleet_asset_id,kind,admission_id,effect,description,actor_id,occurred_at,reason) VALUES (?,?,'workshop',?,'maintenance','Ingreso',?,'2026-10-12T12:00:00Z','Prueba concurrente')")
            ->execute([$cause, $asset, $admission, $actor]);
        $connection->prepare('UPDATE fleet_assets SET service_status=workshop_asset_projection(id) WHERE id=?')->execute([$asset]);
        $connection->prepare("INSERT INTO workshop_operations(id,admission_id,impediment_id,operation_type,actor_id,occurred_at,reason,after_values,confirmed_result,request_key,request_fingerprint,idempotency_scope) VALUES (?,?,?,'admission_create',?,'2026-10-12T12:00:00Z','Prueba concurrente','{}','{}',?,?,'admission_create')")
            ->execute([(string) Str::uuid(), $admission, $cause, $actor, (string) Str::uuid(), str_repeat('a', 64)]);
    }

    public function test_assignment_wins_then_intake_preserves_and_warns(): void
    {
        [$actor, $asset, $trip, $other] = $this->fixture();
        DB::beginTransaction();
        app(AssignmentAdministration::class)->assign($actor, $trip->id, 'tractor_unit', $asset->id, 'Asignación antes de ingreso');
        try {
            $other->beginTransaction();
            $other->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $this->insertAdmission($other, $asset->id, $actor->id);
                $this->fail('El ingreso debe esperar a la asignación.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }
        } finally {
            $other->rollBack();
        }
        DB::commit();
        app(WorkshopAvailability::class)->admit($actor, $asset->id, (string) Str::uuid(), ['kind' => 'corrective', 'admitted_at' => '2026-10-12T12:00:00Z', 'reason' => 'Ingreso posterior']);
        $this->assertSame(1, DB::table('trip_asset_assignments')->count());
        $this->assertSame(0, DB::table('trip_asset_releases')->count());
        $this->assertSame(1, DB::table('workshop_warnings')->count());
        $this->assertSame('pending', $trip->fresh()->execution_status);
        $this->assertSame('maintenance', $asset->fresh()->service_status);
    }

    public function test_intake_wins_and_new_assignment_is_rejected_after_wait(): void
    {
        [$actor, $asset, $trip, $other] = $this->fixture();
        DB::beginTransaction();
        $this->insertAdmission(DB::connection()->getPdo(), $asset->id, $actor->id);
        $other->beginTransaction();
        $other->exec("SET LOCAL lock_timeout='100ms'");
        try {
            $this->insertAssignment($other, $trip->id, $asset->id, $actor->id);
            $this->fail('Asignación debe esperar al ingreso.');
        } catch (PDOException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        } finally {
            $other->rollBack();
        }
        DB::commit();
        $other->beginTransaction();
        try {
            $this->insertAssignment($other, $trip->id, $asset->id, $actor->id);
            $this->fail('Activo bloqueado no puede recibir nueva asignación.');
        } catch (PDOException $exception) {
            $this->assertSame('23514', $exception->getCode());
        } finally {
            $other->rollBack();
        }
        $this->assertSame(0, DB::table('trip_asset_assignments')->count());
        $this->assertSame(1, DB::table('workshop_admissions')->count());
        $this->assertSame('maintenance', $asset->fresh()->service_status);
    }

    public function test_stale_repeatable_read_intake_cannot_ignore_committed_cause(): void
    {
        [$actor, $asset, $trip, $other] = $this->fixture();
        $other->beginTransaction();
        $other->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertSame(0, (int) $other->query('SELECT count(*) FROM workshop_admissions')->fetchColumn());
        app(WorkshopAvailability::class)->admit($actor, $asset->id, (string) Str::uuid(), ['kind' => 'corrective', 'admitted_at' => '2026-10-12T12:00:00Z', 'reason' => 'Primer ingreso']);
        try {
            $this->insertAdmission($other, $asset->id, $actor->id);
            $this->fail('Snapshot previo debe abortar.');
        } catch (PDOException $exception) {
            $this->assertSame('40001', $exception->getCode());
        } finally {
            $other->rollBack();
        }
        $this->assertSame(1, DB::table('workshop_admissions')->count());
    }

    public function test_exit_waits_for_new_cause_and_rejects_old_approval(): void
    {
        [$actor, $asset, $trip, $other] = $this->fixture();
        $service = app(WorkshopAvailability::class);
        $admission = $service->admit($actor, $asset->id, (string) Str::uuid(), ['kind' => 'corrective', 'admitted_at' => '2026-10-12T12:00:00Z', 'reason' => 'Ingreso']);
        $order = (string) Str::uuid();
        DB::beginTransaction();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Falla', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => now(), 'created_by_id' => $actor->id]);
        DB::table('workshop_operations')->insert(['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => 'order_create', 'actor_id' => $actor->id, 'occurred_at' => now(), 'reason' => 'Prueba', 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'idempotency_scope' => 'order_create']);
        DB::commit();
        $service->linkOrder($actor, $order, $admission['admission_id'], (string) Str::uuid(), 'Revisión');
        $request = $service->requestExit($actor, $admission['admission_id'], (string) Str::uuid(), ['resolve_ids' => [$admission['impediment_id']], 'exited_at' => '2026-10-12T13:00:00Z', 'reason' => 'Salida revisada']);
        $decision = app(WorkshopAuthorizations::class)->decide($actor, $request['request_id'], (string) Str::uuid(), 'approved', 'Revisado');
        $cause = (string) Str::uuid();
        $other->beginTransaction();
        try {
            $other->prepare("INSERT INTO fleet_operating_impediments(id,fleet_asset_id,kind,work_order_id,effect,description,actor_id,occurred_at,reason) VALUES (?,?,'workshop',?,'out_of_service','Nueva avería',?,'2026-10-12T12:30:00Z','Prueba')")
                ->execute([$cause, $asset->id, $order, $actor->id]);
            $other->prepare('UPDATE fleet_assets SET service_status=workshop_asset_projection(id) WHERE id=?')->execute([$asset->id]);
            $other->prepare("INSERT INTO workshop_operations(id,fleet_asset_id,impediment_id,operation_type,actor_id,occurred_at,reason,after_values,confirmed_result,request_key,request_fingerprint,idempotency_scope) VALUES (?,?,?,'impediment_record',?,'2026-10-12T12:30:00Z','Prueba','{}','{}',?,?,'impediment_record')")
                ->execute([(string) Str::uuid(), $asset->id, $cause, $actor->id, (string) Str::uuid(), str_repeat('a', 64)]);
            config(['workshop.max_attempts' => 1, 'workshop.lock_timeout_ms' => 100]);
            try {
                $service->exit($actor, $decision['decision_id'], (string) Str::uuid());
                $this->fail('Salida debe esperar a la causa nueva.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
            $other->commit();
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
        try {
            $service->exit($actor, $decision['decision_id'], (string) Str::uuid());
            $this->fail('Revisión vieja no autoriza resolver una nueva causa.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('workshop_admission_exits')->count());
        $this->assertSame('out_of_service', $asset->fresh()->service_status);
    }
}
