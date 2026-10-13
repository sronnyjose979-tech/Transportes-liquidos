<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE fleet_operating_impediments (
 id uuid PRIMARY KEY, fleet_asset_id uuid NOT NULL REFERENCES fleet_assets(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 kind text NOT NULL CHECK(kind IN ('workshop','reviewed_other_cause','historical_observation')),
 admission_id uuid, work_order_id uuid, effect text NOT NULL CHECK(effect IN ('maintenance','out_of_service')),
 description text NOT NULL CHECK(btrim(description)<>''), actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 occurred_at timestamptz(6) NOT NULL, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), reason text NOT NULL CHECK(btrim(reason)<>''),
 FOREIGN KEY(admission_id,fleet_asset_id) REFERENCES workshop_admissions(id,fleet_asset_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(work_order_id,fleet_asset_id) REFERENCES workshop_work_orders(id,fleet_asset_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CHECK((kind='workshop' AND num_nonnulls(admission_id,work_order_id)>0) OR (kind IN ('reviewed_other_cause','historical_observation') AND num_nonnulls(admission_id,work_order_id)=0)),
 CHECK(NOT(kind='workshop' AND admission_id IS NOT NULL AND work_order_id IS NULL) OR effect='maintenance')
);
CREATE UNIQUE INDEX workshop_admission_cause ON fleet_operating_impediments(admission_id) WHERE kind='workshop' AND admission_id IS NOT NULL AND work_order_id IS NULL;
CREATE INDEX ON fleet_operating_impediments(fleet_asset_id,effect,registered_at,id);
CREATE TABLE fleet_impediment_resolutions (
 id uuid PRIMARY KEY, impediment_id uuid NOT NULL UNIQUE REFERENCES fleet_operating_impediments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 operation_id uuid NOT NULL REFERENCES workshop_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
 resolved_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 resolved_at timestamptz(6) NOT NULL, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), reason text NOT NULL CHECK(btrim(reason)<>''), evidence jsonb NOT NULL CHECK(jsonb_typeof(evidence)='object')
);
CREATE INDEX ON fleet_impediment_resolutions(resolved_at,id);
CREATE TABLE workshop_admission_exits (
 id uuid PRIMARY KEY, admission_id uuid NOT NULL UNIQUE REFERENCES workshop_admissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 authorization_application_id uuid NOT NULL UNIQUE REFERENCES workshop_authorization_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 exited_at timestamptz(6) NOT NULL, recorded_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), reason text NOT NULL CHECK(btrim(reason)<>''), reviewed_content jsonb NOT NULL CHECK(jsonb_typeof(reviewed_content)='object')
);
CREATE INDEX ON workshop_admission_exits(exited_at,id);
CREATE TABLE workshop_warnings (
 id uuid PRIMARY KEY, admission_id uuid NOT NULL REFERENCES workshop_admissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 trip_asset_assignment_id uuid REFERENCES trip_asset_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 work_order_id uuid REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 code text NOT NULL CHECK(code='assigned_trip'), context jsonb NOT NULL CHECK(jsonb_typeof(context)='object'),
 captured_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp()
);
CREATE UNIQUE INDEX workshop_assignment_warning ON workshop_warnings(admission_id,trip_asset_assignment_id,code) WHERE trip_asset_assignment_id IS NOT NULL;
CREATE INDEX ON workshop_warnings(admission_id,registered_at,id);
ALTER TABLE workshop_operations ADD COLUMN impediment_id uuid REFERENCES fleet_operating_impediments(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE workshop_operations ADD COLUMN warning_id uuid REFERENCES workshop_warnings(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operations_operation_type_check;
ALTER TABLE workshop_operations ADD CONSTRAINT workshop_operations_operation_type_check CHECK(operation_type IN ('report_create','report_revise','order_create','order_update','report_link_create','report_link_invalidate','mechanic_assign','mechanic_release','diagnosis_record','diagnosis_revise','order_admission_link_create','order_admission_link_invalidate','scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen','admission_create','admission_exit','admission_rectify','impediment_record','impediment_resolve','historical_observe','historical_resolve','warning_note'));
ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operations_check1;
ALTER TABLE workshop_operations ADD CONSTRAINT workshop_operation_versions CHECK((from_version IS NULL AND to_version IS NULL) OR (from_version IS NOT NULL AND to_version IS NOT NULL AND from_version>0 AND to_version=from_version+1 AND num_nonnulls(work_order_id,admission_id)=1));
CREATE UNIQUE INDEX workshop_admission_version ON workshop_operations(admission_id,to_version) WHERE to_version IS NOT NULL;
SQL);
        foreach (['fleet_operating_impediments' => ['admission_id', 'work_order_id', 'actor_id'], 'fleet_impediment_resolutions' => ['operation_id', 'resolved_by_id'], 'workshop_admission_exits' => ['recorded_by_id'], 'workshop_warnings' => ['trip_asset_assignment_id', 'work_order_id', 'captured_by_id'], 'workshop_operations' => ['impediment_id', 'warning_id']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("CREATE INDEX ON {$table} ({$column})");
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX workshop_admission_version');
        DB::statement('ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operation_versions');
        DB::statement('ALTER TABLE workshop_operations ADD CONSTRAINT workshop_operations_check1 CHECK((from_version IS NULL AND to_version IS NULL) OR (from_version IS NOT NULL AND to_version IS NOT NULL AND from_version>0 AND to_version=from_version+1 AND work_order_id IS NOT NULL))');
        DB::statement('ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operations_operation_type_check');
        DB::statement("ALTER TABLE workshop_operations ADD CONSTRAINT workshop_operations_operation_type_check CHECK(operation_type IN ('report_create','report_revise','order_create','order_update','report_link_create','report_link_invalidate','mechanic_assign','mechanic_release','diagnosis_record','diagnosis_revise','order_admission_link_create','order_admission_link_invalidate','scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen'))");
        DB::statement('ALTER TABLE workshop_operations DROP COLUMN impediment_id, DROP COLUMN warning_id');
        foreach (['workshop_warnings', 'workshop_admission_exits', 'fleet_impediment_resolutions', 'fleet_operating_impediments'] as $table) {
            DB::statement("DROP TABLE {$table}");
        }
    }
};
