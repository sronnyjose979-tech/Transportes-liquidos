<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class WorkshopOperationMatrixTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_existing_command_cannot_smuggle_a_new_sensitive_destination(): void
    {
        $actor = $this->actor();
        $asset = FleetAsset::create(['code' => 'MATRIX-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);
        $order = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Prueba matriz', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_by_id' => $actor->id, 'created_at' => now()]);
        $operation = ['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => 'order_create', 'actor_id' => $actor->id, 'occurred_at' => now(), 'reason' => 'Prueba', 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'idempotency_scope' => 'matrix'];
        DB::table('workshop_operations')->insert($operation);
        foreach (['activity_id', 'time_event_id', 'delivery_id', 'usage_id', 'cost_record_id', 'attachment_id', 'material_need_id', 'external_repair_id'] as $destination) {
            try {
                DB::transaction(fn () => DB::table('workshop_operations')->insert(array_replace($operation, ['id' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(), $destination => (string) Str::uuid()])));
                $this->fail('Destino sensible ajeno aceptado.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(1, DB::table('workshop_operations')->where('work_order_id', $order)->count());
    }
}
