<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Workshop\Models\WorkshopReport;
use App\Modules\Workshop\Models\WorkshopWorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class WorkshopCoreTest extends PostgresTestCase
{
    use TransportFixtures;

    private function asset(): FleetAsset
    {
        $asset = FleetAsset::create(['code' => 'WORKSHOP-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);

        return $asset;
    }

    private function operation(string $type, string $root, string $id, int $actor, array $extra = []): string
    {
        $operation = (string) Str::uuid();
        DB::table('workshop_operations')->insert($extra + [
            'id' => $operation, $root => $id, 'operation_type' => $type,
            'actor_id' => $actor, 'occurred_at' => '2026-10-12T12:00:00Z', 'reason' => 'Prueba sintética',
            'after_values' => '{}', 'request_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64), 'idempotency_scope' => $type,
            'confirmed_result' => '{}',
        ]);

        return $operation;
    }

    private function order(?FleetAsset $asset = null, ?int $actor = null): string
    {
        $asset ??= $this->asset();
        $actor ??= $this->actor()->id;
        $id = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $id, 'fleet_asset_id' => $asset->id,
            'kind' => 'corrective', 'description' => 'Inspección sintética', 'priority' => 'normal',
            'status' => 'pending_diagnosis', 'created_at' => '2026-10-12T12:00:00Z', 'created_by_id' => $actor]);
        $this->operation('order_create', 'work_order_id', $id, $actor);

        return $id;
    }

    private function report(FleetAsset $asset, int $actor, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('workshop_reports')->insert($extra + ['id' => $id, 'fleet_asset_id' => $asset->id,
            'description' => 'Falla comunicada', 'recorded_by_id' => $actor, 'occurred_at' => '2026-10-12T12:00:00Z',
            'reason' => 'Prueba sintética', 'root_record_id' => $id, 'revision' => 1]);
        $this->operation(isset($extra['previous_report_id']) ? 'report_revise' : 'report_create', 'report_id', $id, $actor);

        return $id;
    }

    public function test_core_has_nine_tables_uuid_keys_and_timezone_aware_facts(): void
    {
        $tables = ['workshop_reports', 'workshop_admissions', 'workshop_work_orders', 'workshop_report_links',
            'workshop_mechanic_assignments', 'workshop_mechanic_releases', 'workshop_diagnoses',
            'workshop_operations', 'workshop_order_admission_links'];
        foreach ($tables as $table) {
            $column = DB::selectOne('SELECT data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?', ['public', $table, 'id']);
            $this->assertSame('uuid', $column->data_type, $table);
        }
        $column = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name='workshop_reports' AND column_name='occurred_at'");
        $this->assertSame('timestamp with time zone', $column->data_type);
    }

    public function test_several_orders_and_unlinked_report_can_share_one_asset(): void
    {
        $asset = $this->asset();
        $actor = $this->actor('secretary');
        $this->report($asset, $actor->id);
        $this->order($asset, $actor->id);
        $this->order($asset, $actor->id);
        $this->checkConstraints();
        $this->assertSame(2, DB::table('workshop_work_orders')->where('fleet_asset_id', $asset->id)->count());
        $this->assertSame(0, DB::table('workshop_report_links')->count());
        $this->assertSame('available', $asset->fresh()->service_status);
    }

    public function test_order_creation_requires_audit_and_office_actor(): void
    {
        $asset = $this->asset();
        $actor = $this->actor();
        $this->checkConstraints();
        $this->rejected(fn () => DB::table('workshop_work_orders')->insert(['id' => (string) Str::uuid(), 'fleet_asset_id' => $asset->id,
            'kind' => 'corrective', 'description' => 'Sin auditoría', 'priority' => 'normal', 'status' => 'pending_diagnosis',
            'created_at' => '2026-10-12T12:00:00Z', 'created_by_id' => $actor->id]));
        $driver = $this->actor('driver');
        $this->rejected(fn () => $this->order($asset, $driver->id));
        $inactive = $this->actor('administrator', false);
        $this->rejected(fn () => $this->order($asset, $inactive->id));
        $this->assertSame(0, DB::table('workshop_work_orders')->count());
    }

    public function test_history_and_orders_cannot_be_mutated_without_authorized_audit(): void
    {
        $asset = $this->asset();
        $report = $this->report($asset, $this->actor()->id);
        $order = $this->order($asset);
        $this->checkConstraints();
        $this->rejected(fn () => DB::table('workshop_reports')->where('id', $report)->update(['description' => 'Sobrescrito']));
        $this->rejected(fn () => DB::table('workshop_reports')->where('id', $report)->delete());
        $this->rejected(fn () => DB::statement('TRUNCATE workshop_reports CASCADE'));
        $this->rejected(fn () => DB::table('workshop_work_orders')->where('id', $order)->update(['priority' => 'urgent', 'version' => 2]));
        $this->assertSame('normal', DB::table('workshop_work_orders')->where('id', $order)->value('priority'));
    }

    public function test_report_revision_preserves_original_and_rejects_branches_and_foreign_asset(): void
    {
        $actor = $this->actor();
        $asset = $this->asset();
        $other = $this->asset();
        $root = $this->report($asset, $actor->id);
        $leaf = $this->report($asset, $actor->id, ['root_record_id' => $root, 'previous_report_id' => $root, 'revision' => 2]);
        $this->checkConstraints();
        $this->rejected(fn () => $this->report($asset, $actor->id, ['root_record_id' => $root, 'previous_report_id' => $root, 'revision' => 2]), ['23505', '23514']);
        $this->rejected(fn () => $this->report($other, $actor->id, ['root_record_id' => $root, 'previous_report_id' => $leaf, 'revision' => 3]));
        $this->assertSame(2, DB::table('workshop_reports')->where('root_record_id', $root)->count());
    }

    public function test_receipt_identity_fingerprint_and_json_are_database_protected(): void
    {
        $actor = $this->actor();
        $order = $this->order(null, $actor->id);
        $this->checkConstraints();
        $receipt = (array) DB::table('workshop_operations')->where('work_order_id', $order)->first();
        $this->rejected(fn () => DB::table('workshop_operations')->insert(array_replace($receipt, ['id' => (string) Str::uuid()])), '23505');
        $this->rejected(fn () => $this->operation('order_create', 'work_order_id', $order, $actor->id, ['request_fingerprint' => 'short']));
        $this->rejected(fn () => $this->operation('order_create', 'work_order_id', $order, $actor->id, ['confirmed_result' => '[]']));
        $this->rejected(fn () => DB::table('workshop_operations')->where('id', $receipt['id'])->delete());
    }

    public function test_links_require_same_asset_and_models_preserve_relations(): void
    {
        $actor = $this->actor();
        $asset = $this->asset();
        $report = $this->report($asset, $actor->id);
        $order = $this->order($asset, $actor->id);
        $other = $this->order(null, $actor->id);
        $insert = function (string $target) use ($report, $actor): void {
            $id = (string) Str::uuid();
            DB::table('workshop_report_links')->insert(['id' => $id, 'report_id' => $report,
                'work_order_id' => $target, 'linked_by_id' => $actor->id, 'reason' => 'Enlace sintético']);
            $this->operation('report_link_create', 'work_order_id', $target, $actor->id, ['report_link_id' => $id]);
        };
        $insert($order);
        $this->checkConstraints();
        $this->rejected(fn () => $insert($other));
        $this->rejected(fn () => $insert($order), ['23505', '23514']);
        $model = WorkshopWorkOrder::findOrFail($order);
        $this->assertTrue($model->fleetAsset->is($asset));
        $this->assertSame($actor->id, $model->createdBy->id);
        $this->assertTrue($model->reportLinks->first()->report->is(WorkshopReport::findOrFail($report)));
        $this->assertSame(1, $model->version);
    }

    public function test_mechanic_membership_release_and_diagnosis_scope_are_enforced(): void
    {
        $admin = $this->actor();
        $mechanic = $this->actor('mechanic');
        $employee = Employee::create(['user_id' => $mechanic->id, 'name' => 'Mecánico sintético', 'is_active' => true]);
        $order = $this->order(null, $admin->id);
        $other = $this->order(null, $admin->id);
        $assign = function () use ($order, $employee, $admin): string {
            $id = (string) Str::uuid();
            DB::table('workshop_mechanic_assignments')->insert(['id' => $id, 'work_order_id' => $order,
                'employee_id' => $employee->id, 'starts_at' => '2026-10-12T10:00:00Z',
                'assigned_by_id' => $admin->id, 'reason' => 'Participación sintética']);
            $this->operation('mechanic_assign', 'work_order_id', $order, $admin->id, ['mechanic_assignment_id' => $id]);

            return $id;
        };
        $assignment = $assign();
        $diagnose = function (string $target) use ($assignment, $mechanic): void {
            $id = (string) Str::uuid();
            DB::table('workshop_diagnoses')->insert(['id' => $id, 'work_order_id' => $target,
                'mechanic_assignment_id' => $assignment, 'problem' => 'Falla', 'probable_cause' => 'Desgaste',
                'proposed_repair' => 'Revisión', 'actor_id' => $mechanic->id, 'occurred_at' => '2026-10-12T12:00:00Z',
                'reason' => 'Diagnóstico sintético', 'root_record_id' => $id, 'revision' => 1]);
            $this->operation('diagnosis_record', 'work_order_id', $target, $mechanic->id, ['diagnosis_id' => $id]);
        };
        $diagnose($order);
        $this->checkConstraints();
        $this->rejected(fn () => $assign());
        $this->rejected(fn () => $diagnose($other));
        $release = function (string $endsAt) use ($assignment, $admin, $order): void {
            $id = (string) Str::uuid();
            DB::table('workshop_mechanic_releases')->insert(['id' => $id, 'assignment_id' => $assignment,
                'ends_at' => $endsAt, 'recorded_by_id' => $admin->id, 'reason' => 'Fin de participación']);
            $this->operation('mechanic_release', 'work_order_id', $order, $admin->id,
                ['mechanic_assignment_id' => $assignment, 'mechanic_release_id' => $id]);
        };
        $this->rejected(fn () => $release('2026-10-12T09:00:00Z'));
        $release('2026-10-12T13:00:00Z');
        $this->checkConstraints();
        $this->rejected(fn () => $diagnose($order));
        $model = WorkshopWorkOrder::findOrFail($order);
        $this->assertSame($employee->id, $model->mechanicAssignments->first()->employee->id);
        $this->assertNotNull($model->mechanicAssignments->first()->release);
        $this->assertSame(1, $model->diagnoses()->count());
    }

    public function test_invalid_foreign_asset_and_catalog_values_are_rejected(): void
    {
        $actor = $this->actor();
        $asset = $this->asset();
        $this->checkConstraints();
        $base = ['id' => (string) Str::uuid(), 'fleet_asset_id' => $asset->id, 'kind' => 'corrective',
            'description' => 'Prueba', 'priority' => 'normal', 'status' => 'pending_diagnosis',
            'created_at' => '2026-10-12T12:00:00Z', 'created_by_id' => $actor->id];
        foreach (['kind' => 'unknown', 'priority' => 'unknown', 'status' => 'unknown', 'description' => ' '] as $field => $value) {
            $this->rejected(fn () => DB::table('workshop_work_orders')->insert(array_replace($base, [$field => $value])));
        }
        $this->rejected(fn () => DB::table('workshop_work_orders')->insert(array_replace($base, ['fleet_asset_id' => (string) Str::uuid()])), '23503');
    }

    public function test_order_update_requires_exact_snapshots_and_real_version_change(): void
    {
        $actor = $this->actor('secretary');
        $order = $this->order(null, $actor->id);
        $this->checkConstraints();
        $before = DB::selectOne('SELECT to_jsonb(w)::text AS snapshot FROM workshop_work_orders w WHERE id=?', [$order])->snapshot;
        DB::table('workshop_work_orders')->where('id', $order)->update(['description' => 'Inspección ampliada', 'priority' => 'high', 'version' => 2]);
        $after = DB::selectOne('SELECT to_jsonb(w)::text AS snapshot FROM workshop_work_orders w WHERE id=?', [$order])->snapshot;
        $this->operation('order_update', 'work_order_id', $order, $actor->id,
            ['from_version' => 1, 'to_version' => 2, 'before_values' => $before, 'after_values' => $after]);
        $this->checkConstraints();
        $this->assertSame(2, WorkshopWorkOrder::findOrFail($order)->version);
        $this->assertSame('high', WorkshopWorkOrder::findOrFail($order)->priority);
        $this->rejected(fn () => $this->operation('order_update', 'work_order_id', $order, $actor->id,
            ['from_version' => 2, 'to_version' => 3, 'before_values' => $after, 'after_values' => $after]));
        $this->rejected(function () use ($order, $actor, $after): void {
            DB::table('workshop_work_orders')->where('id', $order)->update(['priority' => 'urgent', 'version' => 3]);
            $this->operation('order_update', 'work_order_id', $order, $actor->id,
                ['from_version' => 2, 'to_version' => 3, 'before_values' => $after, 'after_values' => '{}']);
        });
        $this->assertSame('high', WorkshopWorkOrder::findOrFail($order)->priority);
    }

    public function test_fact_audit_rejects_wrong_actor_and_wrong_root(): void
    {
        $actor = $this->actor();
        $otherActor = $this->actor();
        $asset = $this->asset();
        $report = $this->report($asset, $actor->id);
        $first = $this->order($asset, $actor->id);
        $second = $this->order($asset, $actor->id);
        $this->checkConstraints();
        $link = function (string $root, int $auditActor) use ($first, $report, $actor): void {
            $id = (string) Str::uuid();
            DB::table('workshop_report_links')->insert(['id' => $id, 'report_id' => $report,
                'work_order_id' => $first, 'linked_by_id' => $actor->id, 'reason' => 'Prueba de correlación']);
            $this->operation('report_link_create', 'work_order_id', $root, $auditActor, ['report_link_id' => $id]);
        };
        $this->rejected(fn () => $link($first, $otherActor->id));
        $this->rejected(fn () => $link($second, $actor->id));
        $this->assertSame(0, DB::table('workshop_report_links')->count());
    }

    public function test_mechanic_can_report_without_order_or_workshop_admission(): void
    {
        $mechanic = $this->actor('mechanic');
        $asset = $this->asset();
        $report = $this->report($asset, $mechanic->id);
        $this->checkConstraints();
        $this->assertSame($mechanic->id, WorkshopReport::findOrFail($report)->recordedBy->id);
        $this->assertSame(0, DB::table('workshop_work_orders')->count());
        $this->assertSame(0, DB::table('workshop_admissions')->count());
        $this->assertSame('available', $asset->fresh()->service_status);
    }

    public function test_administrator_diagnosis_revisions_preserve_original_and_validate_chain(): void
    {
        $admin = $this->actor();
        $order = $this->order(null, $admin->id);
        $diagnose = function (array $extra = []) use ($order, $admin): string {
            $id = (string) Str::uuid();
            DB::table('workshop_diagnoses')->insert($extra + ['id' => $id, 'work_order_id' => $order,
                'problem' => 'Ruido', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Inspección',
                'actor_id' => $admin->id, 'occurred_at' => '2026-10-12T12:00:00Z', 'reason' => 'Revisión sintética',
                'root_record_id' => $id, 'revision' => 1]);
            $this->operation(isset($extra['previous_diagnosis_id']) ? 'diagnosis_revise' : 'diagnosis_record',
                'work_order_id', $order, $admin->id, ['diagnosis_id' => $id]);

            return $id;
        };
        $root = $diagnose();
        $leaf = $diagnose(['root_record_id' => $root, 'previous_diagnosis_id' => $root,
            'revision' => 2, 'probable_cause' => 'Causa corregida']);
        $this->checkConstraints();
        $this->rejected(fn () => $diagnose(['root_record_id' => (string) Str::uuid(),
            'previous_diagnosis_id' => $leaf, 'revision' => 3]), ['23503', '23514']);
        $this->rejected(fn () => $diagnose(['root_record_id' => $root,
            'previous_diagnosis_id' => $root, 'revision' => 2]), ['23505', '23514']);
        $this->assertSame('Desgaste', DB::table('workshop_diagnoses')->where('id', $root)->value('probable_cause'));
        $this->assertSame(2, WorkshopWorkOrder::findOrFail($order)->diagnoses()->count());
    }

    public function test_admission_remains_disabled_and_does_not_change_availability(): void
    {
        $asset = $this->asset();
        $actor = $this->actor();
        $this->checkConstraints();
        $this->rejected(fn () => DB::table('workshop_admissions')->insert(['id' => (string) Str::uuid(), 'fleet_asset_id' => $asset->id,
            'kind' => 'corrective', 'admitted_at' => '2026-10-12T12:00:00Z', 'admitted_by_id' => $actor->id, 'reason' => 'Prueba']));
        $this->assertSame(0, DB::table('workshop_admissions')->count());
        $this->assertSame('available', $asset->fresh()->service_status);
    }

    public function test_core_migrations_reverse_and_reapply_without_touching_fleet(): void
    {
        $extensions = array_map(fn ($path) => require $path, glob(database_path('migrations/2026_10_12_0002*.php')));
        foreach (array_reverse($extensions) as $extension) {
            $extension->down();
        }
        $schema = require database_path('migrations/2026_10_12_000180_create_workshop_core.php');
        $protection = require database_path('migrations/2026_10_12_000190_protect_workshop_core.php');
        $protection->down();
        $schema->down();
        $this->assertNull(DB::selectOne("SELECT to_regclass('workshop_reports') AS table_name")->table_name);
        $this->assertNotNull(DB::selectOne("SELECT to_regclass('fleet_assets') AS table_name")->table_name);
        $schema->up();
        $protection->up();
        foreach ($extensions as $extension) {
            $extension->up();
        }
        $this->assertNotNull(DB::selectOne("SELECT to_regclass('workshop_reports') AS table_name")->table_name);
        $this->order();
        $this->checkConstraints();
    }
}
