<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Workshop\Application\WorkshopCommands;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopCommandTest extends PostgresTestCase
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
        $actor = $this->actor();
        $asset = FleetAsset::create(['code' => 'RETRY-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);
        $order = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Prueba', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => now(), 'created_by_id' => $actor->id]);
        DB::table('workshop_operations')->insert(['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => 'order_create', 'actor_id' => $actor->id, 'reason' => 'Prueba', 'occurred_at' => now(), 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'idempotency_scope' => 'order_create', 'request_fingerprint' => str_repeat('a', 64)]);
        $this->checkConstraints();
        DB::commit();
        config(['workshop.retry_wait_ms' => [[1, 1]]]);

        return [$actor, $order];
    }

    public function test_safe_transient_retries_whole_transaction_and_replays_without_new_effect(): void
    {
        [$actor, $order] = $this->fixture();
        $commands = app(WorkshopCommands::class);
        $key = (string) Str::uuid();
        $attempts = 0;
        $ids = [];
        $execute = function ($fresh, string $operationId) use ($order, &$attempts, &$ids): array {
            $attempts++;
            $ids[] = $operationId;
            $before = json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$order])->data, true);
            DB::table('workshop_work_orders')->where('id', $order)->update(['priority' => 'high', 'version' => $before['version'] + 1]);
            if ($attempts === 1) {
                DB::unprepared(<<<'SQL'
                    DO $$ BEGIN RAISE EXCEPTION 'Fallo transitorio sintético' USING ERRCODE='40001'; END $$;
                    SQL);
            }
            $after = json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$order])->data, true);

            return ['operation' => ['work_order_id' => $order, 'operation_type' => 'order_update', 'reason' => 'Prueba', 'from_version' => $before['version'], 'to_version' => $after['version'], 'before_values' => $before, 'after_values' => $after], 'result' => ['order_id' => $order]];
        };
        $authorize = fn ($fresh) => $commands->authorizeRoles($fresh, ['administrator']);
        $result = $commands->run($actor, 'order_update:'.$order, $key, ['priority' => 'high'], $authorize, $execute);
        $this->assertSame(2, $attempts);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(2, DB::table('workshop_work_orders')->where('id', $order)->value('version'));
        $this->assertSame($result, $commands->run($actor, 'order_update:'.$order, $key, ['priority' => 'high'], $authorize, $execute));
        $this->assertSame(2, $attempts);
        $this->assertSame(1, DB::table('workshop_operations')->where('operation_type', 'order_update')->count());
    }

    public function test_only_transient_failures_retry_and_attempt_limit_is_configurable(): void
    {
        [$actor, $order] = $this->fixture();
        $commands = app(WorkshopCommands::class);
        foreach (['40001' => 3, '23514' => 1] as $state => $expectedAttempts) {
            $attempts = 0;
            try {
                $commands->run($actor, 'order_update:'.$order, (string) Str::uuid(), ['state' => $state],
                    fn ($fresh) => $commands->authorizeRoles($fresh, ['administrator']),
                    function () use (&$attempts, $state, $order): array {
                        $attempts++;
                        DB::table('workshop_work_orders')->where('id', $order)->update(['priority' => 'high', 'version' => 2]);
                        DB::unprepared("DO \$\$ BEGIN RAISE EXCEPTION 'Prueba sintética' USING ERRCODE='{$state}'; END \$\$;");

                        return [];
                    });
                $this->fail('El fallo debe propagarse tras rollback.');
            } catch (QueryException $exception) {
                $this->assertSame((string) $state, $exception->errorInfo[0]);
            }
            $this->assertSame($expectedAttempts, $attempts);
            $this->assertSame(1, DB::table('workshop_work_orders')->where('id', $order)->value('version'));
            $this->assertSame('normal', DB::table('workshop_work_orders')->where('id', $order)->value('priority'));
        }
    }
}
