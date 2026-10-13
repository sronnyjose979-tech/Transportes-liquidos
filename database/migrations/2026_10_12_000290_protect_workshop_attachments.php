<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION workshop_attachment_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE reservation workshop_operations; terminal workshop_operations; a workshop_attachments; order_id uuid; reservation_id uuid; total integer; previous workshop_attachments;
            BEGIN
                IF TG_TABLE_NAME='workshop_attachments' THEN reservation_id=NEW.reservation_operation_id; order_id=NEW.work_order_id;
                ELSE
                    IF NEW.operation_type NOT IN ('attachment_reserve','attachment_confirm','attachment_release') THEN RETURN NULL; END IF;
                    order_id=NEW.work_order_id;
                    reservation_id=CASE WHEN NEW.operation_type='attachment_reserve' THEN NEW.id ELSE NEW.reservation_operation_id END;
                END IF;
                PERFORM 1 FROM workshop_work_orders WHERE id=order_id FOR UPDATE;
                SELECT * INTO reservation FROM workshop_operations WHERE id=reservation_id;
                IF reservation.operation_type IS DISTINCT FROM 'attachment_reserve' OR reservation.work_order_id IS DISTINCT FROM order_id THEN
                    RAISE EXCEPTION 'Reserva incompatible' USING ERRCODE='23514';
                END IF;
                SELECT * INTO terminal FROM workshop_operations WHERE reservation_operation_id=reservation_id;
                SELECT * INTO a FROM workshop_attachments WHERE reservation_operation_id=reservation_id;
                IF terminal.operation_type='attachment_confirm' THEN
                    IF a.id IS NULL OR a.id IS DISTINCT FROM terminal.attachment_id OR a.work_order_id IS DISTINCT FROM order_id OR a.uploaded_by_id IS DISTINCT FROM terminal.actor_id THEN
                        RAISE EXCEPTION 'Confirmación sin adjunto coherente' USING ERRCODE='23514';
                    END IF;
                ELSIF a.id IS NOT NULL THEN RAISE EXCEPTION 'Adjunto sin confirmación' USING ERRCODE='23514'; END IF;
                IF a.id IS NOT NULL AND (a.category IS DISTINCT FROM reservation.after_values->>'category' OR a.mime IS DISTINCT FROM reservation.after_values->>'mime' OR a.extension IS DISTINCT FROM reservation.after_values->>'extension' OR a.size_bytes IS DISTINCT FROM (reservation.after_values->>'size_bytes')::bigint OR a.checksum IS DISTINCT FROM reservation.after_values->>'checksum' OR a.storage_key IS DISTINCT FROM reservation.id::text||'.'||a.extension OR a.uploaded_by_id IS DISTINCT FROM reservation.actor_id OR a.external_repair_id IS DISTINCT FROM (reservation.after_values->>'external_repair_id')::uuid OR a.previous_attachment_id IS DISTINCT FROM (reservation.after_values->>'previous_attachment_id')::uuid) THEN
                    RAISE EXCEPTION 'Metadata distinta de reserva' USING ERRCODE='23514';
                END IF;
                IF a.id IS NOT NULL AND a.previous_attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM workshop_authorization_applications ap WHERE ap.operation_id=terminal.id) THEN
                    RAISE EXCEPTION 'Revisión sin aplicación sensible' USING ERRCODE='23514';
                END IF;
                IF a.id IS NOT NULL AND a.previous_attachment_id IS NOT NULL THEN
                    SELECT * INTO previous FROM workshop_attachments WHERE id=a.previous_attachment_id;
                    IF previous.work_order_id IS DISTINCT FROM a.work_order_id OR previous.root_record_id IS DISTINCT FROM a.root_record_id OR previous.revision+1<>a.revision THEN
                        RAISE EXCEPTION 'Revisión incompatible' USING ERRCODE='23514';
                    END IF;
                END IF;
                SELECT (SELECT count(*) FROM workshop_attachments WHERE work_order_id=order_id)+(SELECT count(*) FROM workshop_operations r WHERE r.work_order_id=order_id AND r.operation_type='attachment_reserve' AND NOT EXISTS(SELECT 1 FROM workshop_operations t WHERE t.reservation_operation_id=r.id)) INTO total;
                IF total>coalesce(nullif(current_setting('workshop.attachment_limit', true), '')::integer,20) THEN RAISE EXCEPTION 'Máximo veinte adjuntos y reservas' USING ERRCODE='23514'; END IF;
                RETURN NULL;
            END; $$;
            CREATE FUNCTION workshop_attachment_actor() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE target_order uuid; owner bigint;
            BEGIN
                target_order=NEW.work_order_id;
                PERFORM workshop_require_actor(NEW.actor_id,ARRAY['administrator','secretary','mechanic']);
                IF NEW.work_order_id IS NULL OR NEW.admission_id IS NOT NULL OR NEW.report_id IS NOT NULL OR NEW.fleet_asset_id IS NOT NULL OR NEW.from_version IS NOT NULL OR NEW.to_version IS NOT NULL THEN
                    RAISE EXCEPTION 'Raíz de archivo incompatible' USING ERRCODE='23514';
                END IF;
                IF NOT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=NEW.actor_id AND r.name IN ('administrator','secretary')) AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.work_order_id=target_order AND e.user_id=NEW.actor_id AND e.is_active AND a.starts_at<=clock_timestamp() AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_releases r WHERE r.assignment_id=a.id AND r.ends_at<=clock_timestamp())) THEN
                    RAISE EXCEPTION 'Participación no vigente' USING ERRCODE='42501';
                END IF;
                IF NEW.operation_type='attachment_reserve' THEN
                    IF NEW.reason IS NULL OR btrim(NEW.reason)='' THEN RAISE EXCEPTION 'Motivo requerido' USING ERRCODE='23514'; END IF;
                    IF NEW.after_values->>'category' NOT IN ('photo','document','invoice') OR NEW.after_values->>'extension' NOT IN ('pdf','jpg','jpeg','png') OR NEW.after_values->>'mime' NOT IN ('application/pdf','image/jpeg','image/png') OR NEW.after_values->>'checksum' !~ '^[0-9a-f]{64}$' OR NOT NEW.after_values ?& ARRAY['category','extension','mime','checksum','size_bytes'] OR (NEW.after_values->>'size_bytes')::bigint NOT BETWEEN 1 AND 10000000 THEN
                        RAISE EXCEPTION 'Reserva inválida' USING ERRCODE='23514';
                    END IF;
                    IF NOT ((NEW.after_values->>'extension' IN ('jpg','jpeg') AND NEW.after_values->>'mime'='image/jpeg') OR (NEW.after_values->>'extension'='png' AND NEW.after_values->>'mime'='image/png') OR (NEW.after_values->>'extension'='pdf' AND NEW.after_values->>'mime'='application/pdf')) THEN RAISE EXCEPTION 'Formato de reserva incompatible' USING ERRCODE='23514'; END IF;
                ELSE
                    SELECT actor_id INTO owner FROM workshop_operations WHERE id=NEW.reservation_operation_id AND operation_type='attachment_reserve' AND work_order_id=target_order;
                    IF owner IS NULL OR (owner<>NEW.actor_id AND (NEW.operation_type='attachment_confirm' OR NOT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=NEW.actor_id AND r.name='administrator'))) THEN
                        RAISE EXCEPTION 'Terminal no autorizado' USING ERRCODE='42501';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER workshop_attachment_actor BEFORE INSERT ON workshop_operations FOR EACH ROW WHEN (NEW.operation_type IN ('attachment_reserve','attachment_confirm','attachment_release')) EXECUTE FUNCTION workshop_attachment_actor();
            CREATE TRIGGER workshop_lock BEFORE INSERT ON workshop_attachments FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON workshop_attachments FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history();
            CREATE TRIGGER workshop_no_truncate BEFORE TRUNCATE ON workshop_attachments FOR EACH STATEMENT EXECUTE FUNCTION workshop_preserve_history();
            CREATE CONSTRAINT TRIGGER workshop_attachment_check AFTER INSERT ON workshop_attachments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_attachment_integrity();
            CREATE CONSTRAINT TRIGGER workshop_attachment_operation_check AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_attachment_integrity();
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Preservar evidencia histórica; recuperación hacia adelante.');
    }
};
