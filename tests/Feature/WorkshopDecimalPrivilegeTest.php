<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Inventory\Models\SparePart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class WorkshopDecimalPrivilegeTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_restricted_writer_cannot_bypass_exact_decimal_entry(): void
    {
        $actor = $this->actor();
        $asset = FleetAsset::create(['code' => 'DECIMAL-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);
        $order = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Sintética', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => now(), 'created_by_id' => $actor->id]);
        $this->receipt($actor->id, $order, 'order_create');
        $diagnosis = (string) Str::uuid();
        DB::table('workshop_diagnoses')->insert(['id' => $diagnosis, 'work_order_id' => $order, 'problem' => 'Falla', 'probable_cause' => 'Prueba', 'proposed_repair' => 'Prueba', 'actor_id' => $actor->id, 'occurred_at' => now(), 'reason' => 'Prueba', 'root_record_id' => $diagnosis, 'revision' => 1]);
        $this->receipt($actor->id, $order, 'diagnosis_record', ['diagnosis_id' => $diagnosis]);
        $scope = (string) Str::uuid();
        DB::table('workshop_scopes')->insert(['id' => $scope, 'work_order_id' => $order, 'diagnosis_id' => $diagnosis, 'scope_kind' => 'initial', 'proposed_by_id' => $actor->id, 'description' => 'Prueba', 'root_record_id' => $scope, 'revision' => 1]);
        $task = (string) Str::uuid();
        DB::table('workshop_scope_tasks')->insert(['id' => $task, 'scope_id' => $scope, 'description' => 'Prueba', 'line_number' => 1]);
        $part = SparePart::create(['code' => 'PART-'.Str::uuid(), 'description' => 'Repuesto', 'unit' => 'unidad']);
        $role = 'workshop_decimal_test_'.str_replace('-', '', (string) Str::uuid());
        DB::statement("CREATE ROLE {$role} NOLOGIN NOSUPERUSER");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");
        try {
            DB::statement("SET LOCAL ROLE {$role}");
            $write = fn (string $quantity) => DB::select('SELECT public.workshop_write_scope_part(?::uuid,?::uuid,?::uuid,?::uuid,1,?::text)', [(string) Str::uuid(), $scope, $task, $part->id, $quantity]);
            $this->rejected(fn () => $write('1.000000'), '42501');
            DB::statement('RESET ROLE');
            DB::statement("GRANT EXECUTE ON FUNCTION public.workshop_write_scope_part(uuid,uuid,uuid,uuid,integer,text) TO {$role}");
            DB::statement("SET LOCAL ROLE {$role}");
            foreach (['1.0000001', '1.0000000', 'NaN', 'Infinity', '1e1', '1,25', '0', '1000000000000'] as $quantity) {
                $this->rejected(fn () => $write($quantity));
            }
            $this->rejected(fn () => DB::table('workshop_scope_parts')->insert(['id' => (string) Str::uuid(), 'scope_id' => $scope, 'task_id' => $task, 'spare_part_id' => $part->id, 'line_number' => 1, 'quantity' => '1.0000001', 'unit_snapshot' => 'unidad', 'description_snapshot' => 'Repuesto']), '42501');
            $write('+0001.234567');
        } finally {
            DB::statement('RESET ROLE');
        }
        $this->assertSame('1.234567', DB::table('workshop_scope_parts')->value('quantity'));
        $request = (string) Str::uuid();
        $content = DB::selectOne('SELECT workshop_scope_content(?::uuid)::text AS data', [$scope])->data;
        DB::table('workshop_authorization_requests')->insert(['id' => $request, 'work_order_id' => $order, 'scope_id' => $scope, 'type' => 'repair', 'content' => $content, 'requested_by_id' => $actor->id, 'reason' => 'Prueba']);
        $this->receipt($actor->id, $order, 'scope_request', ['scope_id' => $scope, 'authorization_request_id' => $request]);
        $this->checkConstraints();
    }

    private function receipt(int $actor, string $order, string $type, array $extra = []): void
    {
        DB::table('workshop_operations')->insert($extra + ['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => $type, 'actor_id' => $actor, 'reason' => 'Prueba', 'occurred_at' => now(), 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'idempotency_scope' => $type, 'request_fingerprint' => str_repeat('a', 64)]);
    }
}
