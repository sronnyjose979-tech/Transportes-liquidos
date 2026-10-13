<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopAuthorizationConcurrencyTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function receipt(string $type, string $order, int $actor, array $references = []): string
    {
        $id = (string) Str::uuid();
        DB::table('workshop_operations')->insert($references + ['id' => $id, 'work_order_id' => $order, 'operation_type' => $type,
            'actor_id' => $actor, 'occurred_at' => '2026-10-12T12:00:00Z', 'reason' => 'Concurrencia', 'after_values' => '{}',
            'request_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'idempotency_scope' => $type, 'confirmed_result' => '{}']);

        return $id;
    }

    private function fixture(): array
    {
        $this->prepareWorkshopSchema();
        DB::beginTransaction();
        $actor = $this->actor();
        $asset = FleetAsset::create(['code' => 'CONCURRENT-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);
        $order = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Reparación', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => '2026-10-12T12:00:00Z', 'created_by_id' => $actor->id]);
        $this->receipt('order_create', $order, $actor->id);
        $diagnosis = (string) Str::uuid();
        DB::table('workshop_diagnoses')->insert(['id' => $diagnosis, 'work_order_id' => $order, 'problem' => 'Falla', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Reparar', 'actor_id' => $actor->id, 'occurred_at' => '2026-10-12T12:00:00Z', 'reason' => 'Prueba', 'root_record_id' => $diagnosis, 'revision' => 1]);
        $this->receipt('diagnosis_record', $order, $actor->id, ['diagnosis_id' => $diagnosis]);
        DB::commit();
        $request = app(WorkshopAuthorizations::class)->requestScope($actor, $order, (string) Str::uuid(), ['scope_kind' => 'extension', 'diagnosis_id' => $diagnosis, 'description' => 'Ampliación', 'tasks' => [['line_number' => 1, 'description' => 'Inspección']], 'reason' => 'Prueba']);

        return compact('actor', 'order', 'request');
    }

    private function competingInsert(string $table, array $values): void
    {
        $config = config('database.connections.pgsql_testing');
        $pdo = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = explode(',', DB::selectOne('SHOW search_path')->search_path)[0];
        $this->assertMatchesRegularExpression('/^workshop_service_[a-f0-9]+$/', $schema);
        $pdo->exec('SET search_path TO '.$schema.',public');
        $sql = 'INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')';
        try {
            $pdo->beginTransaction();
            $pdo->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $pdo->prepare($sql)->execute(array_values($values));
                $this->fail('La escritura concurrente debe esperar al coordinador.');
            } catch (PDOException $error) {
                $this->assertSame('55P03', $error->errorInfo[0]);
            }
            $pdo->rollBack();
            DB::commit();
            $pdo->beginTransaction();
            try {
                $pdo->prepare($sql)->execute(array_values($values));
                $this->fail('El ganador confirmado debe impedir un duplicado.');
            } catch (PDOException $error) {
                $this->assertSame('23505', $error->errorInfo[0]);
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function test_concurrent_decisions_serialize_and_cannot_duplicate_a_request(): void
    {
        $f = $this->fixture();
        DB::beginTransaction();
        $decision = (string) Str::uuid();
        $values = ['id' => $decision, 'request_id' => $f['request']['request_id'], 'decision' => 'approved', 'decided_by_id' => $f['actor']->id, 'reason' => 'Prueba'];
        DB::table('workshop_authorization_decisions')->insert($values);
        $this->receipt('authorization_decide', $f['order'], $f['actor']->id, ['scope_id' => $f['request']['scope_id'], 'authorization_request_id' => $f['request']['request_id'], 'authorization_decision_id' => $decision]);
        $values['id'] = (string) Str::uuid();
        $this->competingInsert('workshop_authorization_decisions', $values);
        $this->assertSame(1, DB::table('workshop_authorization_decisions')->count());
    }

    public function test_concurrent_applications_serialize_and_cannot_reuse_a_decision(): void
    {
        $f = $this->fixture();
        $decision = app(WorkshopAuthorizations::class)->decide($f['actor'], $f['request']['request_id'], (string) Str::uuid(), 'approved', 'Prueba');
        DB::beginTransaction();
        $operation = $this->receipt('scope_apply', $f['order'], $f['actor']->id, ['scope_id' => $f['request']['scope_id']]);
        $values = ['id' => (string) Str::uuid(), 'decision_id' => $decision['decision_id'], 'operation_id' => $operation, 'applied_by_id' => $f['actor']->id];
        DB::table('workshop_authorization_applications')->insert($values);
        $values['id'] = (string) Str::uuid();
        $values['operation_id'] = (string) Str::uuid();
        $this->competingInsert('workshop_authorization_applications', $values);
        $this->assertSame(1, DB::table('workshop_authorization_applications')->count());
    }
}
