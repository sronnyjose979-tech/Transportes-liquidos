<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE workshop_scopes (
 id uuid PRIMARY KEY, work_order_id uuid NOT NULL REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 diagnosis_id uuid NOT NULL, scope_kind text NOT NULL CHECK(scope_kind IN ('initial','extension','revision')),
 proposed_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 description text NOT NULL CHECK(btrim(description)<>''), registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 root_record_id uuid NOT NULL REFERENCES workshop_scopes(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
 previous_scope_id uuid UNIQUE REFERENCES workshop_scopes(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
 revision integer NOT NULL CHECK(revision>0), UNIQUE(id,work_order_id), UNIQUE(root_record_id,revision),
 FOREIGN KEY(diagnosis_id,work_order_id) REFERENCES workshop_diagnoses(id,work_order_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CHECK((scope_kind IN ('initial','extension') AND previous_scope_id IS NULL AND root_record_id=id AND revision=1)
 OR (scope_kind='revision' AND previous_scope_id IS NOT NULL AND previous_scope_id<>id AND root_record_id<>id AND revision>1))
);
CREATE TABLE workshop_scope_tasks (
 id uuid PRIMARY KEY, scope_id uuid NOT NULL REFERENCES workshop_scopes(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 description text NOT NULL CHECK(btrim(description)<>''), line_number integer NOT NULL CHECK(line_number>0),
 supplier_id uuid REFERENCES suppliers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 UNIQUE(scope_id,line_number), UNIQUE(id,scope_id)
);
CREATE TABLE workshop_scope_parts (
 id uuid PRIMARY KEY, scope_id uuid NOT NULL REFERENCES workshop_scopes(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 task_id uuid, spare_part_id uuid NOT NULL REFERENCES spare_parts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 line_number integer NOT NULL CHECK(line_number>0), quantity numeric(18,6) NOT NULL CHECK(quantity>0 AND quantity<1000000000000 AND quantity NOT IN ('NaN','Infinity','-Infinity')),
 unit_snapshot text NOT NULL CHECK(btrim(unit_snapshot)<>''), description_snapshot text NOT NULL CHECK(btrim(description_snapshot)<>''),
 UNIQUE(scope_id,line_number), UNIQUE(id,scope_id),
 FOREIGN KEY(task_id,scope_id) REFERENCES workshop_scope_tasks(id,scope_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE TABLE workshop_authorization_requests (
 id uuid PRIMARY KEY, work_order_id uuid REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 admission_id uuid REFERENCES workshop_admissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 scope_id uuid, type text NOT NULL CHECK(type IN ('repair','extension','rectification','reopening','cancellation','exit')),
 previous_request_id uuid UNIQUE REFERENCES workshop_authorization_requests(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
 content jsonb NOT NULL CHECK(jsonb_typeof(content)='object'), target_version bigint CHECK(target_version>0),
 requested_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 reason text NOT NULL CHECK(btrim(reason)<>''), registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(scope_id,work_order_id) REFERENCES workshop_scopes(id,work_order_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CHECK(previous_request_id IS DISTINCT FROM id),
 CHECK((type IN ('repair','extension') AND work_order_id IS NOT NULL AND scope_id IS NOT NULL AND admission_id IS NULL AND target_version IS NULL)
 OR (type='exit' AND admission_id IS NOT NULL AND work_order_id IS NULL AND scope_id IS NULL AND target_version IS NOT NULL)
 OR (type IN ('reopening','cancellation') AND work_order_id IS NOT NULL AND admission_id IS NULL AND scope_id IS NULL AND target_version IS NOT NULL)
 OR (type='rectification' AND num_nonnulls(work_order_id,admission_id)=1 AND (scope_id IS NULL OR work_order_id IS NOT NULL) AND target_version IS NOT NULL))
);
CREATE TABLE workshop_authorization_decisions (
 id uuid PRIMARY KEY, request_id uuid NOT NULL UNIQUE REFERENCES workshop_authorization_requests(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 decision text NOT NULL CHECK(decision IN ('approved','rejected')), decided_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 reason text NOT NULL CHECK(btrim(reason)<>''), registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE workshop_authorization_applications (
 id uuid PRIMARY KEY, decision_id uuid NOT NULL UNIQUE REFERENCES workshop_authorization_decisions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 operation_id uuid NOT NULL REFERENCES workshop_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
 applied_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE workshop_operations ADD scope_id uuid REFERENCES workshop_scopes(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE workshop_operations ADD authorization_request_id uuid REFERENCES workshop_authorization_requests(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE workshop_operations ADD authorization_decision_id uuid REFERENCES workshop_authorization_decisions(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operations_operation_type_check;
ALTER TABLE workshop_operations ADD CONSTRAINT workshop_operations_operation_type_check CHECK(operation_type IN ('report_create','report_revise','order_create','order_update','report_link_create','report_link_invalidate','mechanic_assign','mechanic_release','diagnosis_record','diagnosis_revise','order_admission_link_create','order_admission_link_invalidate','scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen'));
CREATE INDEX ON workshop_scopes(work_order_id,registered_at,id);
CREATE INDEX ON workshop_authorization_requests(type,registered_at,id);
CREATE INDEX ON workshop_authorization_requests(work_order_id,registered_at,id);
CREATE INDEX ON workshop_authorization_requests(admission_id,registered_at,id);
CREATE INDEX ON workshop_authorization_decisions(decision,registered_at,id);
CREATE UNIQUE INDEX workshop_request_record ON workshop_operations(authorization_request_id) WHERE operation_type IN ('scope_request','authorization_request');
CREATE UNIQUE INDEX workshop_decision_record ON workshop_operations(authorization_decision_id) WHERE operation_type='authorization_decide';
SQL);
        foreach (['workshop_scopes' => ['diagnosis_id', 'proposed_by_id'], 'workshop_scope_tasks' => ['supplier_id'], 'workshop_scope_parts' => ['task_id', 'spare_part_id'], 'workshop_authorization_requests' => ['scope_id', 'requested_by_id'], 'workshop_authorization_decisions' => ['decided_by_id'], 'workshop_authorization_applications' => ['operation_id', 'applied_by_id'], 'workshop_operations' => ['scope_id', 'authorization_request_id', 'authorization_decision_id']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("CREATE INDEX ON {$table} ({$column})");
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workshop_operations DROP COLUMN scope_id, DROP COLUMN authorization_request_id, DROP COLUMN authorization_decision_id');
        DB::statement('ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operations_operation_type_check');
        DB::statement("ALTER TABLE workshop_operations ADD CONSTRAINT workshop_operations_operation_type_check CHECK(operation_type IN ('report_create','report_revise','order_create','order_update','report_link_create','report_link_invalidate','mechanic_assign','mechanic_release','diagnosis_record','diagnosis_revise','order_admission_link_create','order_admission_link_invalidate'))");
        foreach (['workshop_authorization_applications', 'workshop_authorization_decisions', 'workshop_authorization_requests', 'workshop_scope_parts', 'workshop_scope_tasks', 'workshop_scopes'] as $table) {
            DB::statement("DROP TABLE {$table}");
        }
    }
};
