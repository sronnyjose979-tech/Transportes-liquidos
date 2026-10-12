<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE workshop_reports (
                id uuid PRIMARY KEY, fleet_asset_id uuid NOT NULL REFERENCES fleet_assets(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                trip_event_id uuid REFERENCES trip_events(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                description text NOT NULL CHECK (btrim(description)<>''), reporter_id bigint REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                recorded_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                occurred_at timestamptz(6) NOT NULL, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), reason text NOT NULL CHECK (btrim(reason)<>''),
                root_record_id uuid NOT NULL, previous_report_id uuid UNIQUE, revision integer NOT NULL CHECK (revision>0),
                UNIQUE(id,fleet_asset_id), UNIQUE(root_record_id,revision),
                CHECK ((previous_report_id IS NULL AND root_record_id=id AND revision=1) OR (previous_report_id IS NOT NULL AND previous_report_id<>id AND root_record_id<>id AND revision>1)),
                FOREIGN KEY(root_record_id) REFERENCES workshop_reports(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY(previous_report_id) REFERENCES workshop_reports(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED
            );
            CREATE TABLE workshop_admissions (
                id uuid PRIMARY KEY, fleet_asset_id uuid NOT NULL REFERENCES fleet_assets(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                kind text NOT NULL CHECK (kind IN ('corrective','preventive')), admitted_at timestamptz(6) NOT NULL,
                admitted_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                reason text NOT NULL CHECK (btrim(reason)<>''), registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                version bigint NOT NULL DEFAULT 1 CHECK(version>0), UNIQUE(id,fleet_asset_id)
            );
            CREATE TABLE workshop_work_orders (
                id uuid PRIMARY KEY, fleet_asset_id uuid NOT NULL REFERENCES fleet_assets(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                kind text NOT NULL CHECK (kind IN ('corrective','preventive')), description text NOT NULL CHECK(btrim(description)<>''),
                priority text NOT NULL CHECK(priority IN ('low','normal','high','urgent')),
                status text NOT NULL CHECK(status IN ('pending_diagnosis','pending_authorization','authorized','in_repair','finished','closed','cancelled')),
                created_at timestamptz(6) NOT NULL, created_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                version bigint NOT NULL DEFAULT 1 CHECK(version>0), UNIQUE(id,fleet_asset_id)
            );
            CREATE TABLE workshop_report_links (
                id uuid PRIMARY KEY, report_id uuid NOT NULL REFERENCES workshop_reports(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                work_order_id uuid NOT NULL REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                linked_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), reason text NOT NULL CHECK(btrim(reason)<>''), UNIQUE(report_id,work_order_id)
            );
            CREATE TABLE workshop_mechanic_assignments (
                id uuid PRIMARY KEY, work_order_id uuid NOT NULL REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                employee_id uuid NOT NULL REFERENCES employees(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                starts_at timestamptz(6) NOT NULL, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                assigned_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                reason text NOT NULL CHECK(btrim(reason)<>''), UNIQUE(id,work_order_id)
            );
            CREATE TABLE workshop_mechanic_releases (
                id uuid PRIMARY KEY, assignment_id uuid NOT NULL UNIQUE REFERENCES workshop_mechanic_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                ends_at timestamptz(6) NOT NULL, recorded_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                reason text NOT NULL CHECK(btrim(reason)<>''), registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp()
            );
            CREATE TABLE workshop_diagnoses (
                id uuid PRIMARY KEY, work_order_id uuid NOT NULL REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                mechanic_assignment_id uuid REFERENCES workshop_mechanic_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                problem text NOT NULL CHECK(btrim(problem)<>''), probable_cause text NOT NULL CHECK(btrim(probable_cause)<>''), proposed_repair text NOT NULL CHECK(btrim(proposed_repair)<>''),
                actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                occurred_at timestamptz(6) NOT NULL, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), reason text NOT NULL CHECK(btrim(reason)<>''),
                root_record_id uuid NOT NULL, previous_diagnosis_id uuid UNIQUE, revision integer NOT NULL CHECK(revision>0),
                UNIQUE(id,work_order_id), UNIQUE(root_record_id,revision),
                CHECK ((previous_diagnosis_id IS NULL AND root_record_id=id AND revision=1) OR (previous_diagnosis_id IS NOT NULL AND previous_diagnosis_id<>id AND root_record_id<>id AND revision>1)),
                FOREIGN KEY(root_record_id) REFERENCES workshop_diagnoses(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY(previous_diagnosis_id) REFERENCES workshop_diagnoses(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY(mechanic_assignment_id,work_order_id) REFERENCES workshop_mechanic_assignments(id,work_order_id) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            CREATE TABLE workshop_operations (
                id uuid PRIMARY KEY, work_order_id uuid REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                admission_id uuid REFERENCES workshop_admissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                report_id uuid REFERENCES workshop_reports(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                fleet_asset_id uuid REFERENCES fleet_assets(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                operation_type text NOT NULL CHECK(operation_type IN ('report_create','report_revise','order_create','order_update','report_link_create','report_link_invalidate','mechanic_assign','mechanic_release','diagnosis_record','diagnosis_revise','order_admission_link_create','order_admission_link_invalidate')),
                before_values jsonb CHECK(before_values IS NULL OR jsonb_typeof(before_values)='object'),
                after_values jsonb NOT NULL CHECK(jsonb_typeof(after_values)='object'),
                from_version bigint, to_version bigint,
                actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                reason text NOT NULL CHECK(btrim(reason)<>''), occurred_at timestamptz(6) NOT NULL, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                request_key uuid NOT NULL, request_fingerprint text NOT NULL CHECK(request_fingerprint ~ '^[0-9a-f]{64}$'),
                idempotency_scope text NOT NULL CHECK(btrim(idempotency_scope)<>''), confirmed_result jsonb NOT NULL CHECK(jsonb_typeof(confirmed_result)='object'),
                report_link_id uuid REFERENCES workshop_report_links(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                mechanic_assignment_id uuid REFERENCES workshop_mechanic_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                mechanic_release_id uuid REFERENCES workshop_mechanic_releases(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                diagnosis_id uuid REFERENCES workshop_diagnoses(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                order_admission_link_id uuid,
                reservation_operation_id uuid REFERENCES workshop_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                CHECK(num_nonnulls(work_order_id,admission_id,report_id,fleet_asset_id)=1),
                CHECK((from_version IS NULL AND to_version IS NULL) OR (from_version IS NOT NULL AND to_version IS NOT NULL AND from_version>0 AND to_version=from_version+1 AND work_order_id IS NOT NULL)),
                CHECK(reservation_operation_id IS NULL), UNIQUE(actor_id,idempotency_scope,request_key)
            );
            CREATE TABLE workshop_order_admission_links (
                id uuid PRIMARY KEY, work_order_id uuid NOT NULL, admission_id uuid NOT NULL,
                fleet_asset_id uuid NOT NULL REFERENCES fleet_assets(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                linked_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), reason text NOT NULL CHECK(btrim(reason)<>''), operation_id uuid NOT NULL,
                UNIQUE(work_order_id,admission_id),
                FOREIGN KEY(work_order_id,fleet_asset_id) REFERENCES workshop_work_orders(id,fleet_asset_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY(admission_id,fleet_asset_id) REFERENCES workshop_admissions(id,fleet_asset_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY(operation_id) REFERENCES workshop_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED
            );
            ALTER TABLE workshop_operations ADD FOREIGN KEY(order_admission_link_id) REFERENCES workshop_order_admission_links(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
            CREATE UNIQUE INDEX workshop_order_version ON workshop_operations(work_order_id,to_version) WHERE to_version IS NOT NULL;
            CREATE UNIQUE INDEX workshop_report_invalidation ON workshop_operations(report_link_id) WHERE operation_type='report_link_invalidate';
            CREATE UNIQUE INDEX workshop_admission_link_invalidation ON workshop_operations(order_admission_link_id) WHERE operation_type='order_admission_link_invalidate';
            CREATE UNIQUE INDEX workshop_order_creation ON workshop_operations(work_order_id) WHERE operation_type='order_create';
            CREATE UNIQUE INDEX workshop_report_record ON workshop_operations(report_id) WHERE operation_type IN ('report_create','report_revise');
            CREATE UNIQUE INDEX workshop_link_record ON workshop_operations(report_link_id) WHERE operation_type='report_link_create';
            CREATE UNIQUE INDEX workshop_assignment_record ON workshop_operations(mechanic_assignment_id) WHERE operation_type='mechanic_assign';
            CREATE UNIQUE INDEX workshop_release_record ON workshop_operations(mechanic_release_id) WHERE operation_type='mechanic_release';
            CREATE UNIQUE INDEX workshop_diagnosis_record ON workshop_operations(diagnosis_id) WHERE operation_type IN ('diagnosis_record','diagnosis_revise');
            CREATE INDEX ON workshop_reports(fleet_asset_id,occurred_at,id);
            CREATE INDEX ON workshop_admissions(fleet_asset_id,admitted_at,id);
            CREATE INDEX ON workshop_work_orders(fleet_asset_id,status,created_at,id);
            CREATE INDEX ON workshop_work_orders(status,created_at,id);
            CREATE INDEX ON workshop_report_links(work_order_id,registered_at,id);
            CREATE INDEX ON workshop_mechanic_assignments(work_order_id,employee_id,starts_at,id);
            CREATE INDEX ON workshop_mechanic_assignments(employee_id,starts_at,id);
            CREATE INDEX ON workshop_mechanic_releases(ends_at,id);
            CREATE INDEX ON workshop_diagnoses(work_order_id,registered_at,id);
            CREATE INDEX ON workshop_order_admission_links(admission_id,registered_at,id);
            CREATE INDEX ON workshop_order_admission_links(fleet_asset_id,registered_at,id);
            SQL);

        // PostgreSQL does not automatically index the referencing side of a FK.
        $references = [
            'workshop_reports' => ['trip_event_id', 'reporter_id', 'recorded_by_id'],
            'workshop_admissions' => ['admitted_by_id'],
            'workshop_work_orders' => ['created_by_id'],
            'workshop_report_links' => ['linked_by_id'],
            'workshop_mechanic_assignments' => ['assigned_by_id'],
            'workshop_mechanic_releases' => ['recorded_by_id'],
            'workshop_diagnoses' => ['mechanic_assignment_id', 'actor_id'],
            'workshop_operations' => ['report_link_id', 'mechanic_assignment_id', 'mechanic_release_id', 'diagnosis_id', 'order_admission_link_id', 'reservation_operation_id'],
            'workshop_order_admission_links' => ['linked_by_id', 'operation_id'],
        ];
        foreach ($references as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("CREATE INDEX ON {$table} ({$column})");
            }
        }
        foreach (['work_order_id', 'admission_id', 'report_id', 'fleet_asset_id'] as $root) {
            DB::statement("CREATE INDEX ON workshop_operations ({$root}, registered_at, id)");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operations_order_admission_link_id_fkey');
        foreach (['workshop_order_admission_links', 'workshop_operations', 'workshop_diagnoses', 'workshop_mechanic_releases', 'workshop_mechanic_assignments', 'workshop_report_links', 'workshop_work_orders', 'workshop_admissions', 'workshop_reports'] as $table) {
            DB::statement("DROP TABLE {$table}");
        }
    }
};
