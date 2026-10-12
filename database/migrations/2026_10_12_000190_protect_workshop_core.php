<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION workshop_require_actor(actor bigint, permitted text[]) RETURNS void LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1 FROM users WHERE id=actor FOR UPDATE;
                PERFORM 1 FROM user_roles WHERE user_id=actor FOR UPDATE;
                IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
                    WHERE u.id=actor AND u.is_active AND r.name=ANY(permitted)) THEN
                    RAISE EXCEPTION 'Actor de Taller no autorizado' USING ERRCODE='23514';
                END IF;
            END; $$;

            CREATE FUNCTION workshop_preserve_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Historial de Taller inmutable' USING ERRCODE='23514';
            END; $$;

            CREATE FUNCTION workshop_core_record() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE previous jsonb; member workshop_mechanic_assignments; participant users;
            BEGIN
                IF TG_TABLE_NAME='workshop_admissions' THEN
                    RAISE EXCEPTION 'Ingreso no habilitado: requiere integración Flota y Transporte' USING ERRCODE='23514';
                ELSIF TG_TABLE_NAME='workshop_work_orders' THEN
                    IF TG_OP='INSERT' THEN
                        PERFORM workshop_require_actor(NEW.created_by_id, ARRAY['administrator','secretary']);
                        IF NEW.status<>'pending_diagnosis' OR NEW.version<>1 THEN
                            RAISE EXCEPTION 'Estado inicial de orden inválido' USING ERRCODE='23514';
                        END IF;
                    ELSE
                        IF (to_jsonb(NEW)-ARRAY['description','priority','version']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['description','priority','version'])
                            OR NEW.version<>OLD.version+1 THEN
                            RAISE EXCEPTION 'Cambio de orden no habilitado o versión inválida' USING ERRCODE='23514';
                        END IF;
                    END IF;
                ELSIF TG_TABLE_NAME='workshop_reports' THEN
                    PERFORM workshop_require_actor(NEW.recorded_by_id, ARRAY['administrator','secretary','mechanic']);
                    PERFORM 1 FROM fleet_assets WHERE id=NEW.fleet_asset_id FOR UPDATE;
                    IF NEW.trip_event_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM trip_events e JOIN trip_asset_assignments a ON a.trip_id=e.trip_id
                        WHERE e.id=NEW.trip_event_id AND a.fleet_asset_id=NEW.fleet_asset_id AND a.starts_at<=NEW.occurred_at
                          AND NOT EXISTS(SELECT 1 FROM trip_asset_releases r WHERE r.assignment_id=a.id AND r.ends_at<=NEW.occurred_at)) THEN
                        RAISE EXCEPTION 'Evento no corresponde al historial del activo' USING ERRCODE='23514';
                    END IF;
                    IF NEW.previous_report_id IS NOT NULL THEN
                        SELECT to_jsonb(r) INTO previous FROM workshop_reports r WHERE id=NEW.previous_report_id FOR UPDATE;
                        IF previous IS NULL OR previous->>'fleet_asset_id' IS DISTINCT FROM NEW.fleet_asset_id::text
                            OR previous->>'trip_event_id' IS DISTINCT FROM NEW.trip_event_id::text
                            OR previous->>'root_record_id' IS DISTINCT FROM NEW.root_record_id::text
                            OR (previous->>'revision')::integer+1<>NEW.revision THEN
                            RAISE EXCEPTION 'Revisión de reporte incompatible' USING ERRCODE='23514';
                        END IF;
                    END IF;
                ELSIF TG_TABLE_NAME='workshop_report_links' THEN
                    PERFORM workshop_require_actor(NEW.linked_by_id, ARRAY['administrator','secretary']);
                    IF (SELECT fleet_asset_id FROM workshop_reports WHERE id=NEW.report_id) IS DISTINCT FROM
                        (SELECT fleet_asset_id FROM workshop_work_orders WHERE id=NEW.work_order_id) THEN
                        RAISE EXCEPTION 'Reporte y orden de activos distintos' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='workshop_mechanic_assignments' THEN
                    PERFORM workshop_require_actor(NEW.assigned_by_id, ARRAY['administrator','secretary']);
                    PERFORM 1 FROM workshop_work_orders WHERE id=NEW.work_order_id FOR UPDATE;
                    PERFORM 1 FROM employees WHERE id=NEW.employee_id FOR UPDATE;
                    SELECT u.* INTO participant FROM users u JOIN employees e ON e.user_id=u.id WHERE e.id=NEW.employee_id AND e.is_active;
                    IF participant.id IS NULL THEN
                        RAISE EXCEPTION 'Empleado mecánico no activo' USING ERRCODE='23514';
                    END IF;
                    PERFORM workshop_require_actor(participant.id, ARRAY['mechanic']);
                ELSIF TG_TABLE_NAME='workshop_mechanic_releases' THEN
                    PERFORM workshop_require_actor(NEW.recorded_by_id, ARRAY['administrator','secretary']);
                    SELECT * INTO member FROM workshop_mechanic_assignments WHERE id=NEW.assignment_id FOR UPDATE;
                    IF member.id IS NOT NULL AND NEW.ends_at<member.starts_at THEN
                        RAISE EXCEPTION 'Fin de participación anterior a inicio' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='workshop_diagnoses' THEN
                    PERFORM workshop_require_actor(NEW.actor_id, ARRAY['administrator','mechanic']);
                    PERFORM 1 FROM workshop_work_orders WHERE id=NEW.work_order_id FOR UPDATE;
                    IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
                        WHERE u.id=NEW.actor_id AND u.is_active AND r.name='administrator') THEN
                        SELECT * INTO member FROM workshop_mechanic_assignments WHERE id=NEW.mechanic_assignment_id FOR UPDATE;
                        IF member.id IS NULL OR member.work_order_id<>NEW.work_order_id
                            OR EXISTS(SELECT 1 FROM workshop_mechanic_releases WHERE assignment_id=member.id)
                            OR NOT EXISTS(SELECT 1 FROM employees e WHERE e.id=member.employee_id AND e.user_id=NEW.actor_id AND e.is_active) THEN
                            RAISE EXCEPTION 'Mecánico sin participación vigente' USING ERRCODE='23514';
                        END IF;
                    END IF;
                    IF NEW.previous_diagnosis_id IS NOT NULL THEN
                        SELECT to_jsonb(d) INTO previous FROM workshop_diagnoses d WHERE id=NEW.previous_diagnosis_id FOR UPDATE;
                        IF previous IS NULL OR previous->>'work_order_id' IS DISTINCT FROM NEW.work_order_id::text
                            OR previous->>'mechanic_assignment_id' IS DISTINCT FROM NEW.mechanic_assignment_id::text
                            OR previous->>'root_record_id' IS DISTINCT FROM NEW.root_record_id::text
                            OR (previous->>'revision')::integer+1<>NEW.revision THEN
                            RAISE EXCEPTION 'Revisión de diagnóstico incompatible' USING ERRCODE='23514';
                        END IF;
                    END IF;
                ELSIF TG_TABLE_NAME='workshop_order_admission_links' THEN
                    RAISE EXCEPTION 'Vinculación a ingreso pendiente de integración' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;

            CREATE FUNCTION workshop_participation_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE target_order uuid; target_employee uuid;
            BEGIN
                IF TG_TABLE_NAME='workshop_mechanic_assignments' THEN
                    target_order=NEW.work_order_id; target_employee=NEW.employee_id;
                ELSE
                    SELECT work_order_id,employee_id INTO target_order,target_employee
                        FROM workshop_mechanic_assignments WHERE id=NEW.assignment_id;
                END IF;
                IF EXISTS(SELECT 1 FROM workshop_mechanic_assignments a
                    WHERE a.work_order_id=target_order AND a.employee_id=target_employee
                        AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_releases r WHERE r.assignment_id=a.id)
                    GROUP BY a.work_order_id,a.employee_id HAVING count(*)>1) THEN
                    RAISE EXCEPTION 'Participación vigente duplicada' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;

            CREATE FUNCTION workshop_operation_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE target_table text; target_id uuid; target jsonb; actor_field text; expected_root uuid; allowed_fields text[];
            BEGIN
                IF NEW.operation_type IN ('report_create','report_revise') THEN
                    target_table='workshop_reports'; target_id=NEW.report_id; actor_field='recorded_by_id'; allowed_fields=ARRAY[]::text[];
                    IF NEW.work_order_id IS NOT NULL OR NEW.admission_id IS NOT NULL OR NEW.fleet_asset_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Raíz de reporte incorrecta' USING ERRCODE='23514';
                    END IF;
                ELSE
                    IF NEW.work_order_id IS NULL OR NEW.report_id IS NOT NULL OR NEW.admission_id IS NOT NULL OR NEW.fleet_asset_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Raíz de orden incorrecta' USING ERRCODE='23514';
                    END IF;
                    CASE NEW.operation_type
                        WHEN 'order_create','order_update' THEN target_table='workshop_work_orders'; target_id=NEW.work_order_id; actor_field='created_by_id'; allowed_fields=ARRAY[]::text[];
                        WHEN 'report_link_create','report_link_invalidate' THEN target_table='workshop_report_links'; target_id=NEW.report_link_id; actor_field='linked_by_id'; allowed_fields=ARRAY['report_link_id'];
                        WHEN 'mechanic_assign' THEN target_table='workshop_mechanic_assignments'; target_id=NEW.mechanic_assignment_id; actor_field='assigned_by_id'; allowed_fields=ARRAY['mechanic_assignment_id'];
                        WHEN 'mechanic_release' THEN target_table='workshop_mechanic_releases'; target_id=NEW.mechanic_release_id; actor_field='recorded_by_id'; allowed_fields=ARRAY['mechanic_assignment_id','mechanic_release_id'];
                        WHEN 'diagnosis_record','diagnosis_revise' THEN target_table='workshop_diagnoses'; target_id=NEW.diagnosis_id; actor_field='actor_id'; allowed_fields=ARRAY['diagnosis_id'];
                        ELSE RAISE EXCEPTION 'Operación pendiente de integración' USING ERRCODE='23514';
                    END CASE;
                END IF;
                IF target_id IS NULL OR EXISTS(SELECT 1 FROM unnest(ARRAY['report_link_id','mechanic_assignment_id','mechanic_release_id','diagnosis_id','order_admission_link_id']) f
                    WHERE NOT (f=ANY(allowed_fields)) AND to_jsonb(NEW)->>f IS NOT NULL) THEN
                    RAISE EXCEPTION 'Destinos de operación incompatibles' USING ERRCODE='23514';
                END IF;
                EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id=$1',target_table) INTO target USING target_id;
                IF target IS NULL THEN
                    RAISE EXCEPTION 'Operación sin hecho confirmado' USING ERRCODE='23514';
                END IF;
                IF target_table='workshop_mechanic_releases' THEN
                    SELECT work_order_id INTO expected_root FROM workshop_mechanic_assignments WHERE id=(target->>'assignment_id')::uuid;
                    IF NEW.mechanic_assignment_id IS DISTINCT FROM (target->>'assignment_id')::uuid THEN
                        RAISE EXCEPTION 'Fin de otro participante' USING ERRCODE='23514';
                    END IF;
                ELSIF target_table NOT IN ('workshop_work_orders','workshop_reports') THEN
                    expected_root=(target->>'work_order_id')::uuid;
                ELSE expected_root=NEW.work_order_id;
                END IF;
                IF expected_root IS DISTINCT FROM NEW.work_order_id THEN
                    RAISE EXCEPTION 'Hecho de otra orden' USING ERRCODE='23514';
                END IF;
                IF NEW.operation_type NOT IN ('order_update','report_link_invalidate') AND (target->>actor_field)::bigint IS DISTINCT FROM NEW.actor_id THEN
                    RAISE EXCEPTION 'Actor no corresponde al hecho' USING ERRCODE='23514';
                END IF;
                IF NEW.operation_type IN ('report_create','report_revise') AND
                    ((NEW.operation_type='report_create') IS DISTINCT FROM (target->>'previous_report_id' IS NULL)) THEN
                    RAISE EXCEPTION 'Tipo de revisión de reporte incorrecto' USING ERRCODE='23514';
                END IF;
                IF NEW.operation_type IN ('diagnosis_record','diagnosis_revise') AND
                    ((NEW.operation_type='diagnosis_record') IS DISTINCT FROM (target->>'previous_diagnosis_id' IS NULL)) THEN
                    RAISE EXCEPTION 'Tipo de revisión de diagnóstico incorrecto' USING ERRCODE='23514';
                END IF;
                IF NEW.operation_type='order_update' THEN
                    IF NEW.to_version IS NULL OR NEW.before_values IS NULL
                        OR (target->>'version')::bigint<NEW.to_version
                        OR (NEW.before_values->>'version')::bigint IS DISTINCT FROM NEW.from_version
                        OR (NEW.after_values->>'version')::bigint IS DISTINCT FROM NEW.to_version THEN
                        RAISE EXCEPTION 'Cambio sin versiones/snapshot' USING ERRCODE='23514';
                    END IF;
                ELSIF NEW.from_version IS NOT NULL OR NEW.to_version IS NOT NULL THEN
                    RAISE EXCEPTION 'Evento no incrementa raíz mutable' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;

            CREATE FUNCTION workshop_operation_actor() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.operation_type IN ('report_create','report_revise') THEN
                    PERFORM workshop_require_actor(NEW.actor_id, ARRAY['administrator','secretary','mechanic']);
                ELSIF NEW.operation_type IN ('diagnosis_record','diagnosis_revise') THEN
                    PERFORM workshop_require_actor(NEW.actor_id, ARRAY['administrator','mechanic']);
                ELSE
                    PERFORM workshop_require_actor(NEW.actor_id, ARRAY['administrator','secretary']);
                END IF;
                RETURN NEW;
            END; $$;

            CREATE FUNCTION workshop_fact_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE field text; expected text; total integer;
            BEGIN
                IF TG_TABLE_NAME='workshop_work_orders' THEN
                    IF TG_OP='UPDATE' THEN
                        SELECT count(*) INTO total FROM workshop_operations o WHERE o.work_order_id=NEW.id AND o.operation_type='order_update'
                          AND o.from_version=OLD.version AND o.to_version=NEW.version AND o.before_values=to_jsonb(OLD) AND o.after_values=to_jsonb(NEW);
                    ELSE
                        SELECT count(*) INTO total FROM workshop_operations o WHERE o.work_order_id=NEW.id AND o.operation_type='order_create' AND o.actor_id=NEW.created_by_id;
                    END IF;
                ELSE
                    CASE TG_TABLE_NAME
                        WHEN 'workshop_reports' THEN field='report_id'; expected=CASE WHEN NEW.previous_report_id IS NULL THEN 'report_create' ELSE 'report_revise' END;
                        WHEN 'workshop_report_links' THEN field='report_link_id'; expected='report_link_create';
                        WHEN 'workshop_mechanic_assignments' THEN field='mechanic_assignment_id'; expected='mechanic_assign';
                        WHEN 'workshop_mechanic_releases' THEN field='mechanic_release_id'; expected='mechanic_release';
                        WHEN 'workshop_diagnoses' THEN field='diagnosis_id'; expected=CASE WHEN NEW.previous_diagnosis_id IS NULL THEN 'diagnosis_record' ELSE 'diagnosis_revise' END;
                    END CASE;
                    SELECT count(*) INTO total FROM workshop_operations o WHERE o.operation_type=expected AND to_jsonb(o)->>field=NEW.id::text;
                END IF;
                IF total<>1 THEN
                    RAISE EXCEPTION 'Hecho sin operación única correspondiente' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;
            SQL);

        $tables = ['workshop_reports', 'workshop_admissions', 'workshop_work_orders', 'workshop_report_links', 'workshop_mechanic_assignments', 'workshop_mechanic_releases', 'workshop_diagnoses', 'workshop_operations', 'workshop_order_admission_links'];
        foreach ($tables as $table) {
            DB::statement("CREATE TRIGGER workshop_lock BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock()");
            $events = $table === 'workshop_work_orders' ? 'DELETE' : 'UPDATE OR DELETE';
            DB::statement("CREATE TRIGGER workshop_history BEFORE {$events} ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history()");
            DB::statement("CREATE TRIGGER workshop_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION workshop_preserve_history()");
            if ($table !== 'workshop_operations') {
                $events = $table === 'workshop_work_orders' ? 'INSERT OR UPDATE' : 'INSERT';
                DB::statement("CREATE TRIGGER workshop_record BEFORE {$events} ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_core_record()");
                if (! in_array($table, ['workshop_admissions', 'workshop_order_admission_links'], true)) {
                    DB::statement("CREATE CONSTRAINT TRIGGER workshop_audit AFTER {$events} ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_fact_audit()");
                }
            }
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER workshop_actor BEFORE INSERT ON workshop_operations FOR EACH ROW EXECUTE FUNCTION workshop_operation_actor();
            CREATE CONSTRAINT TRIGGER workshop_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_operation_integrity();
            CREATE CONSTRAINT TRIGGER workshop_participation AFTER INSERT ON workshop_mechanic_assignments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_participation_integrity();
            CREATE CONSTRAINT TRIGGER workshop_participation AFTER INSERT ON workshop_mechanic_releases DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_participation_integrity();
            SQL);
    }

    public function down(): void
    {
        foreach (['workshop_reports', 'workshop_admissions', 'workshop_work_orders', 'workshop_report_links', 'workshop_mechanic_assignments', 'workshop_mechanic_releases', 'workshop_diagnoses', 'workshop_operations', 'workshop_order_admission_links'] as $table) {
            foreach (['workshop_lock', 'workshop_history', 'workshop_no_truncate', 'workshop_record', 'workshop_audit', 'workshop_actor', 'workshop_operation', 'workshop_participation'] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
            }
        }
        foreach (['workshop_fact_audit()', 'workshop_operation_actor()', 'workshop_operation_integrity()', 'workshop_participation_integrity()', 'workshop_core_record()', 'workshop_preserve_history()', 'workshop_require_actor(bigint,text[])'] as $function) {
            DB::statement("DROP FUNCTION {$function}");
        }
    }
};
