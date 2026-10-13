<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['activities' => ['task_id uuid NOT NULL REFERENCES workshop_scope_tasks(id) ON DELETE RESTRICT ON UPDATE RESTRICT, mechanic_assignment_id uuid REFERENCES workshop_mechanic_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT, description text NOT NULL CHECK(btrim(description)<>\'\'), observations text', 'previous_activity_id'], 'time_events' => ['work_order_id uuid NOT NULL REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT, mechanic_assignment_id uuid NOT NULL, event_type text NOT NULL CHECK(event_type IN (\'start\',\'pause\',\'resume\',\'finish\')), FOREIGN KEY(mechanic_assignment_id,work_order_id) REFERENCES workshop_mechanic_assignments(id,work_order_id) ON DELETE RESTRICT ON UPDATE RESTRICT', 'previous_event_id']] as $name => [$columns, $previous]) {
            DB::statement("CREATE TABLE workshop_{$name} (id uuid PRIMARY KEY, {$columns}, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT, occurred_at timestamptz(6) NOT NULL, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), reason text NOT NULL CHECK(btrim(reason)<>''), root_record_id uuid NOT NULL REFERENCES workshop_{$name}(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, {$previous} uuid UNIQUE REFERENCES workshop_{$name}(id) ON DELETE RESTRICT ON UPDATE RESTRICT, revision integer NOT NULL CHECK(revision>0), UNIQUE(root_record_id,revision), CHECK(({$previous} IS NULL AND root_record_id=id AND revision=1) OR ({$previous} IS NOT NULL AND {$previous}<>id AND root_record_id<>id AND revision>1)))");
            foreach (['actor_id', 'mechanic_assignment_id'] as $field) {
                DB::statement("CREATE INDEX ON workshop_{$name} ({$field})");
            }
        }
        DB::statement('CREATE INDEX ON workshop_activities(task_id,occurred_at,id)');
        DB::statement('CREATE INDEX ON workshop_time_events(work_order_id)');
        DB::statement('CREATE INDEX ON workshop_time_events(mechanic_assignment_id,occurred_at,id)');
        DB::statement('ALTER TABLE workshop_operations ADD COLUMN activity_id uuid REFERENCES workshop_activities(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, ADD COLUMN time_event_id uuid REFERENCES workshop_time_events(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED');
        foreach (['activity_id', 'time_event_id'] as $field) {
            DB::statement("CREATE UNIQUE INDEX ON workshop_operations({$field}) WHERE {$field} IS NOT NULL");
        }
        $check = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='workshop_operations'::regclass AND conname='workshop_operations_operation_type_check'")->definition;
        DB::statement('ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operations_operation_type_check');
        DB::statement('ALTER TABLE workshop_operations ADD CONSTRAINT workshop_operations_operation_type_check '.str_replace('ARRAY[', "ARRAY['activity_record'::text,'activity_revise'::text,'time_record'::text,'time_revise'::text,'order_finish'::text,'order_close'::text,", $check));
    }

    public function down(): void
    {
        $check = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='workshop_operations'::regclass AND conname='workshop_operations_operation_type_check'")->definition;
        $check = preg_replace("/'(?:activity_record|activity_revise|time_record|time_revise|order_finish|order_close)'::text, ?/", '', $check);
        DB::statement('ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operations_operation_type_check');
        DB::statement('ALTER TABLE workshop_operations ADD CONSTRAINT workshop_operations_operation_type_check '.$check);
        DB::statement('ALTER TABLE workshop_operations DROP COLUMN activity_id, DROP COLUMN time_event_id');
        DB::statement('DROP TABLE workshop_time_events,workshop_activities');
    }
};
