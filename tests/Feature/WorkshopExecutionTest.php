<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use App\Modules\Workshop\Application\WorkshopExecution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use PDOException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopExecutionTest extends PostgresTestCase
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
        DB::beginTransaction();
        $admin = $this->actor();
        $mechanic = $this->actor('mechanic');
        $employee = Employee::create(['user_id' => $mechanic->id, 'name' => 'Mecánico prueba', 'is_active' => true]);
        $asset = FleetAsset::create(['code' => 'EXEC-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);
        $order = (string) Str::uuid();
        $assignment = (string) Str::uuid();
        $diagnosis = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Reparación', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => now(), 'created_by_id' => $admin->id]);
        $this->receipt($admin->id, $order, 'order_create');
        DB::table('workshop_mechanic_assignments')->insert(['id' => $assignment, 'work_order_id' => $order, 'employee_id' => $employee->id, 'starts_at' => '2026-10-12T08:00:00Z', 'assigned_by_id' => $admin->id, 'reason' => 'Asignado']);
        $this->receipt($admin->id, $order, 'mechanic_assign', ['mechanic_assignment_id' => $assignment]);
        DB::table('workshop_diagnoses')->insert(['id' => $diagnosis, 'work_order_id' => $order, 'problem' => 'Falla', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Reparar', 'actor_id' => $admin->id, 'occurred_at' => now(), 'reason' => 'Prueba', 'root_record_id' => $diagnosis, 'revision' => 1]);
        $this->receipt($admin->id, $order, 'diagnosis_record', ['diagnosis_id' => $diagnosis]);
        DB::commit();
        $authorizations = app(WorkshopAuthorizations::class);
        $scope = $authorizations->requestScope($admin, $order, (string) Str::uuid(), ['diagnosis_id' => $diagnosis, 'description' => 'Alcance', 'reason' => 'Prueba', 'tasks' => [['line_number' => 1, 'description' => 'Reparar']]]);
        $decision = $authorizations->decide($admin, $scope['request_id'], (string) Str::uuid(), 'approved', 'Aprobado');
        $authorizations->applyScope($admin, $decision['decision_id'], (string) Str::uuid());
        $task = DB::table('workshop_scope_tasks')->where('scope_id', $scope['scope_id'])->value('id');

        return compact('admin', 'mechanic', 'order', 'assignment', 'task');
    }

    private function receipt(int $actor, string $order, string $type, array $extra = []): void
    {
        DB::table('workshop_operations')->insert($extra + ['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => $type, 'actor_id' => $actor, 'reason' => 'Prueba', 'occurred_at' => now(), 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'idempotency_scope' => $type, 'request_fingerprint' => str_repeat('a', 64)]);
    }

    private function event(array $f, string $type, string $at, array $extra = []): array
    {
        return app(WorkshopExecution::class)->recordTime($f['mechanic'], (string) Str::uuid(), $extra + ['work_order_id' => $f['order'], 'mechanic_assignment_id' => $f['assignment'], 'event_type' => $type, 'occurred_at' => '2026-10-12T'.$at.'Z', 'reason' => 'Trabajo']);
    }

    public function test_offset_dates_preserve_the_instant_and_fractional_time_in_postgres(): void
    {
        $f = $this->fixture();
        $service = app(WorkshopExecution::class);
        $activity = $service->recordActivity($f['mechanic'], (string) Str::uuid(), [
            'task_id' => $f['task'], 'mechanic_assignment_id' => $f['assignment'],
            'description' => 'Verificación de huso horario', 'reason' => 'Prueba sintética', 'version' => 3,
            'occurred_at' => '2026-10-12T03:00:00.100001-06:00',
        ]);
        $base = ['work_order_id' => $f['order'], 'mechanic_assignment_id' => $f['assignment'], 'reason' => 'Prueba sintética'];
        $start = $service->recordTime($f['mechanic'], (string) Str::uuid(), $base + ['event_type' => 'start', 'occurred_at' => '2026-10-12T03:00:00.100001-06:00']);
        $service->recordTime($f['mechanic'], (string) Str::uuid(), $base + ['event_type' => 'finish', 'occurred_at' => '2026-10-12T03:00:01.100002-06:00']);
        $this->assertSame('1.000001', $service->times($f['mechanic'], $f['order'])['person_seconds']);
        $this->assertSame('2026-10-12 09:00:00.100001', DB::selectOne("SELECT to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS instant FROM workshop_activities WHERE id=?", [$activity['activity_id']])->instant);
        $this->assertSame('2026-10-12 09:00:00.100001', DB::selectOne("SELECT to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS instant FROM workshop_time_events WHERE id=?", [$start['time_event_id']])->instant);
    }

    public function test_pause_resume_finish_and_idempotent_start_exclude_pauses(): void
    {
        $f = $this->fixture();
        $service = app(WorkshopExecution::class);
        $key = (string) Str::uuid();
        $payload = ['work_order_id' => $f['order'], 'mechanic_assignment_id' => $f['assignment'], 'event_type' => 'start', 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Trabajo', 'version' => 3];
        $result = $service->recordTime($f['mechanic'], $key, $payload);
        $this->assertSame($result, $service->recordTime($f['mechanic'], $key, $payload));
        $this->event($f, 'pause', '10:00:00');
        $this->event($f, 'resume', '11:00:00');
        $this->event($f, 'finish', '12:00:00');
        $this->assertEquals(7200, $service->times($f['mechanic'], $f['order'])['person_seconds']);
        $this->assertSame('in_repair', DB::table('workshop_work_orders')->where('id', $f['order'])->value('status'));
    }

    public function test_sequence_conflicts_rollback_and_rectification_rebuilds_history(): void
    {
        $f = $this->fixture();
        $this->event($f, 'start', '09:00:00', ['version' => 3]);
        try {
            $this->event($f, 'resume', '10:00:00');
            $this->fail('Secuencia incompatible aceptada');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $finish = $this->event($f, 'finish', '11:00:00');
        $this->event($f, 'finish', '12:00:00', ['previous_event_id' => $finish['time_event_id']]);
        $this->assertSame(3, DB::table('workshop_time_events')->count());
        $this->assertEquals(10800, app(WorkshopExecution::class)->times($f['mechanic'], $f['order'])['person_seconds']);
    }

    public function test_activity_finish_and_admin_close_preserve_participant_open_interval(): void
    {
        $f = $this->fixture();
        $service = app(WorkshopExecution::class);
        $activity = $service->recordActivity($f['mechanic'], (string) Str::uuid(), ['task_id' => $f['task'], 'mechanic_assignment_id' => $f['assignment'], 'description' => 'Reparado', 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Trabajo', 'version' => 3]);
        $this->event($f, 'start', '09:00:00');
        $service->recordActivity($f['mechanic'], (string) Str::uuid(), ['task_id' => $f['task'], 'mechanic_assignment_id' => $f['assignment'], 'description' => 'Texto rectificado', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Corrección', 'previous_activity_id' => $activity['activity_id']]);
        $service->transition($f['mechanic'], $f['order'], (string) Str::uuid(), ['status' => 'finished', 'version' => 4, 'mechanic_assignment_id' => $f['assignment'], 'occurred_at' => '2026-10-12T12:00:00Z', 'reason' => 'Terminado']);
        $service->transition($f['admin'], $f['order'], (string) Str::uuid(), ['status' => 'closed', 'version' => 5, 'occurred_at' => '2026-10-12T13:00:00Z', 'reason' => 'Revisado', 'review' => ['works' => 'Revisados', 'parts' => 'Ninguno', 'times' => 'Revisados', 'pending' => 'Ninguno']]);
        $this->assertSame(1, DB::table('workshop_time_events')->count());
        $this->assertNotNull($service->times($f['mechanic'], $f['order'])['participants'][$f['assignment']]['open_since']);
    }

    public function test_subsecond_intervals_are_aggregated_exactly(): void
    {
        $f = $this->fixture();
        $this->event($f, 'start', '09:00:00.100001', ['version' => 3]);
        $this->event($f, 'finish', '09:00:01.100002');
        $this->assertSame('1.000001', app(WorkshopExecution::class)->times($f['mechanic'], $f['order'])['person_seconds']);
    }

    public function test_terminal_revision_requires_matching_applied_authorization(): void
    {
        $f = $this->fixture();
        $service = app(WorkshopExecution::class);
        $this->event($f, 'start', '09:00:00', ['version' => 3]);
        $finish = $this->event($f, 'finish', '10:00:00');
        $service->transition($f['mechanic'], $f['order'], (string) Str::uuid(), ['status' => 'finished', 'version' => 4, 'mechanic_assignment_id' => $f['assignment'], 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Terminado']);
        $before = json_decode(DB::selectOne('SELECT to_jsonb(e)::text AS data FROM workshop_time_events e WHERE id=?', [$finish['time_event_id']])->data, true);
        $after = ['work_order_id' => $f['order'], 'mechanic_assignment_id' => $f['assignment'], 'event_type' => 'finish', 'occurred_at' => '2026-10-12T11:00:00Z', 'reason' => 'Corrección'];
        $authorizations = app(WorkshopAuthorizations::class);
        $request = $authorizations->request($f['admin'], (string) Str::uuid(), ['work_order_id' => $f['order'], 'type' => 'rectification', 'target_version' => 5, 'reason' => 'Corrección', 'content' => ['fact_table' => 'workshop_time_events', 'fact_id' => $finish['time_event_id'], 'before' => $before, 'after' => $after, 'executor_id' => $f['mechanic']->id]]);
        $decision = $authorizations->decide($f['admin'], $request['request_id'], (string) Str::uuid(), 'approved', 'Revisado');
        try {
            $service->recordTime($f['mechanic'], (string) Str::uuid(), $after + ['previous_event_id' => $finish['time_event_id']]);
            $this->fail('Una decisión sin aplicación no habilita la rectificación.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('decision_id', $error->errors());
        }
        $this->assertSame(2, DB::table('workshop_time_events')->count());
        $service->recordTime($f['mechanic'], (string) Str::uuid(), $after + ['previous_event_id' => $finish['time_event_id'], 'decision_id' => $decision['decision_id']]);
        $this->assertSame(3, DB::table('workshop_time_events')->count());
        $this->assertSame(7200, $service->times($f['mechanic'], $f['order'])['person_seconds']);
        $this->assertTrue(DB::table('workshop_authorization_applications')->where('decision_id', $decision['decision_id'])->exists());
    }

    public function test_unassigned_mechanic_is_denied(): void
    {
        $f = $this->fixture();
        $other = $this->actor('mechanic');
        $this->expectException(AuthorizationException::class);
        app(WorkshopExecution::class)->recordTime($other, (string) Str::uuid(), ['work_order_id' => $f['order'], 'mechanic_assignment_id' => $f['assignment'], 'event_type' => 'start', 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Trabajo', 'version' => 3]);
    }

    public function test_concurrent_rectifications_serialize_and_prevent_branches(): void
    {
        $f = $this->fixture();
        $this->event($f, 'start', '09:00:00', ['version' => 3]);
        $finish = $this->event($f, 'finish', '11:00:00');
        $id = (string) Str::uuid();
        $values = ['id' => $id, 'work_order_id' => $f['order'], 'mechanic_assignment_id' => $f['assignment'], 'event_type' => 'finish', 'occurred_at' => '2026-10-12T12:00:00Z', 'actor_id' => $f['mechanic']->id, 'reason' => 'Rectificación', 'previous_event_id' => $finish['time_event_id'], 'root_record_id' => $finish['time_event_id'], 'revision' => 2];
        DB::beginTransaction();
        DB::table('workshop_time_events')->insert($values);
        $this->receipt($f['mechanic']->id, $f['order'], 'time_revise', ['time_event_id' => $id]);
        $config = config('database.connections.pgsql_testing');
        $pdo = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = explode(',', DB::selectOne('SHOW search_path')->search_path)[0];
        $this->assertMatchesRegularExpression('/^workshop_service_[a-f0-9]+$/', $schema);
        $pdo->exec('SET search_path TO '.$schema.',public');
        $values['id'] = (string) Str::uuid();
        $sql = 'INSERT INTO workshop_time_events ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')';
        try {
            $pdo->beginTransaction();
            $pdo->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $pdo->prepare($sql)->execute(array_values($values));
                $this->fail('Debe serializarse con la primera corrección.');
            } catch (PDOException $error) {
                $this->assertSame('55P03', $error->errorInfo[0]);
            }
            $pdo->rollBack();
            DB::commit();
            $pdo->beginTransaction();
            try {
                $pdo->prepare($sql)->execute(array_values($values));
                $this->fail('No puede bifurcar la historia.');
            } catch (PDOException $error) {
                $this->assertSame('23505', $error->errorInfo[0]);
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $this->assertSame(3, DB::table('workshop_time_events')->count());
    }
}
