<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE workshop_attachments (
                id uuid PRIMARY KEY, work_order_id uuid NOT NULL REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                category text NOT NULL CHECK(category IN ('photo','document','invoice')),
                external_repair_id uuid, storage_key text NOT NULL UNIQUE,
                mime text NOT NULL, extension text NOT NULL,
                size_bytes bigint NOT NULL CHECK(size_bytes BETWEEN 1 AND 10000000),
                checksum text NOT NULL CHECK(checksum ~ '^[0-9a-f]{64}$'),
                uploaded_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                root_record_id uuid NOT NULL, previous_attachment_id uuid UNIQUE, revision integer NOT NULL CHECK(revision>0),
                reservation_operation_id uuid NOT NULL UNIQUE REFERENCES workshop_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                UNIQUE(id,work_order_id), UNIQUE(root_record_id,revision),
                CHECK ((previous_attachment_id IS NULL AND root_record_id=id AND revision=1) OR (previous_attachment_id IS NOT NULL AND previous_attachment_id<>id AND root_record_id<>id AND revision>1)),
                CHECK((extension IN ('jpg','jpeg') AND mime='image/jpeg') OR (extension='png' AND mime='image/png') OR (extension='pdf' AND mime='application/pdf')),
                FOREIGN KEY(root_record_id) REFERENCES workshop_attachments(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY(previous_attachment_id) REFERENCES workshop_attachments(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY(external_repair_id,work_order_id) REFERENCES workshop_external_repairs(id,work_order_id) ON DELETE RESTRICT ON UPDATE RESTRICT
            );
            ALTER TABLE workshop_operations ADD attachment_id uuid REFERENCES workshop_attachments(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
            ALTER TABLE workshop_cost_records ADD FOREIGN KEY(source_attachment_id,work_order_id) REFERENCES workshop_attachments(id,work_order_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
            CREATE INDEX ON workshop_attachments(work_order_id,registered_at,id);
            CREATE INDEX ON workshop_attachments(external_repair_id);
            CREATE INDEX ON workshop_attachments(uploaded_by_id);
            CREATE INDEX ON workshop_operations(attachment_id);
            SQL);
        foreach (DB::select("SELECT conname FROM pg_constraint WHERE conrelid='workshop_operations'::regclass AND contype='c' AND pg_get_constraintdef(oid) ~ 'reservation_operation_id IS NULL'") as $constraint) {
            DB::statement('ALTER TABLE workshop_operations DROP CONSTRAINT "'.str_replace('"', '""', $constraint->conname).'"');
        }
        DB::unprepared(<<<'SQL'
            ALTER TABLE workshop_operations ADD CHECK ((reservation_operation_id IS NOT NULL)=(operation_type IN ('attachment_confirm','attachment_release')));
            ALTER TABLE workshop_operations ADD CHECK ((attachment_id IS NOT NULL)=(operation_type='attachment_confirm'));
            CREATE UNIQUE INDEX workshop_attachment_terminal ON workshop_operations(reservation_operation_id) WHERE operation_type IN ('attachment_confirm','attachment_release');
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Preservar evidencia histórica; recuperación mediante migración hacia adelante.');
    }
};
