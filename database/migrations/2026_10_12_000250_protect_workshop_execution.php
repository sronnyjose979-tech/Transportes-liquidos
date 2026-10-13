<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION workshop_scope_is_effective(target uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM workshop_scopes s JOIN workshop_authorization_requests q ON q.scope_id=s.id JOIN workshop_authorization_decisions d ON d.request_id=q.id JOIN workshop_authorization_applications a ON a.decision_id=d.id WHERE s.id=target AND d.decision='approved' AND NOT EXISTS(SELECT 1 FROM workshop_scopes newer JOIN workshop_authorization_requests nq ON nq.scope_id=newer.id JOIN workshop_authorization_decisions nd ON nd.request_id=nq.id JOIN workshop_authorization_applications na ON na.decision_id=nd.id WHERE newer.root_record_id=s.root_record_id AND newer.revision>s.revision));
$$;
CREATE FUNCTION workshop_execution_actor(actor bigint, member uuid, target uuid, moment timestamptz) RETURNS void LANGUAGE plpgsql AS $$
DECLARE admin boolean; assignment workshop_mechanic_assignments;
BEGIN
 PERFORM workshop_require_actor(actor,ARRAY['administrator','mechanic']);
 SELECT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=actor AND r.name='administrator') INTO admin;
 IF member IS NULL THEN
  IF NOT admin THEN RAISE EXCEPTION 'Participación requerida' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO assignment FROM workshop_mechanic_assignments WHERE id=member FOR UPDATE;
  IF assignment.id IS NULL OR assignment.work_order_id<>target OR moment<assignment.starts_at OR EXISTS(SELECT 1 FROM workshop_mechanic_releases WHERE assignment_id=member) THEN RAISE EXCEPTION 'Participación no vigente' USING ERRCODE='23514'; END IF;
  IF NOT admin AND NOT EXISTS(SELECT 1 FROM employees WHERE id=assignment.employee_id AND user_id=actor AND is_active) THEN RAISE EXCEPTION 'Participación ajena/inactiva' USING ERRCODE='23514'; END IF;
 END IF;
END; $$;
CREATE FUNCTION workshop_execution_record() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target uuid; prior jsonb; scope uuid; status text; previous uuid; record jsonb;
BEGIN
 IF TG_TABLE_NAME='workshop_activities' THEN
  SELECT s.work_order_id,s.id INTO target,scope FROM workshop_scope_tasks t JOIN workshop_scopes s ON s.id=t.scope_id WHERE t.id=NEW.task_id;
  previous=NEW.previous_activity_id;
  IF NOT workshop_scope_is_effective(scope) AND previous IS NULL THEN RAISE EXCEPTION 'Tarea fuera de alcance aplicado vigente' USING ERRCODE='23514'; END IF;
 ELSE
  target=NEW.work_order_id; previous=NEW.previous_event_id;
  IF NOT EXISTS(SELECT 1 FROM workshop_scopes s WHERE s.work_order_id=target AND workshop_scope_is_effective(s.id)) THEN RAISE EXCEPTION 'Orden sin alcance aplicado' USING ERRCODE='23514'; END IF;
 END IF;
 SELECT w.status INTO status FROM workshop_work_orders w WHERE id=target FOR UPDATE;
 IF status NOT IN ('authorized','in_repair') AND previous IS NULL THEN RAISE EXCEPTION 'Orden no activa para ejecución' USING ERRCODE='23514'; END IF;
 PERFORM workshop_execution_actor(NEW.actor_id,NEW.mechanic_assignment_id,target,NEW.occurred_at);
 IF previous IS NOT NULL THEN
  EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id=$1 FOR UPDATE',TG_TABLE_NAME) INTO prior USING previous;
  record=to_jsonb(NEW);
  IF prior IS NULL OR prior->>'root_record_id' IS DISTINCT FROM NEW.root_record_id::text OR (prior->>'revision')::integer+1<>NEW.revision OR prior->>'mechanic_assignment_id' IS DISTINCT FROM record->>'mechanic_assignment_id' OR (TG_TABLE_NAME='workshop_activities' AND prior->>'task_id' IS DISTINCT FROM record->>'task_id') OR (TG_TABLE_NAME='workshop_time_events' AND prior->>'work_order_id' IS DISTINCT FROM record->>'work_order_id') THEN RAISE EXCEPTION 'Rectificación de ámbito/cadena incompatible' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION workshop_execution_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE field text; previous text; expected text; row record; state text; moment timestamptz;
BEGIN
 IF TG_TABLE_NAME='workshop_activities' THEN field='activity_id'; previous='previous_activity_id'; expected='activity'; ELSE field='time_event_id'; previous='previous_event_id'; expected='time'; END IF;
 expected=expected || CASE WHEN to_jsonb(NEW)->>previous IS NULL THEN '_record' ELSE '_revise' END;
 IF (SELECT count(*) FROM workshop_operations o WHERE to_jsonb(o)->>field=NEW.id::text AND o.operation_type=expected AND o.actor_id=NEW.actor_id)<>1 THEN RAISE EXCEPTION 'Ejecución sin recibo correlacionado' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='workshop_time_events' THEN
  FOR row IN SELECT e.* FROM workshop_time_events e WHERE e.mechanic_assignment_id=NEW.mechanic_assignment_id AND NOT EXISTS(SELECT 1 FROM workshop_time_events r WHERE r.previous_event_id=e.id) ORDER BY e.occurred_at LOOP
   IF moment IS NOT NULL AND row.occurred_at<=moment THEN RAISE EXCEPTION 'Secuencia temporal ambigua/incompatible' USING ERRCODE='23514'; END IF;
   IF NOT COALESCE(((state IS NULL OR state='finish') AND row.event_type='start' OR state IN ('start','resume') AND row.event_type IN ('pause','finish') OR state='pause' AND row.event_type='resume'),false) THEN RAISE EXCEPTION 'Secuencia de tiempos inválida' USING ERRCODE='23514'; END IF;
   state=row.event_type; moment=row.occurred_at;
  END LOOP;
 END IF;
 IF EXISTS(SELECT 1 FROM workshop_work_orders w WHERE w.id=CASE WHEN TG_TABLE_NAME='workshop_time_events' THEN (to_jsonb(NEW)->>'work_order_id')::uuid ELSE (SELECT s.work_order_id FROM workshop_scope_tasks t JOIN workshop_scopes s ON s.id=t.scope_id WHERE t.id=(to_jsonb(NEW)->>'task_id')::uuid) END AND w.status IN ('finished','closed','cancelled')) AND NOT EXISTS(SELECT 1 FROM workshop_operations o JOIN workshop_authorization_applications a ON a.operation_id=o.id JOIN workshop_authorization_decisions d ON d.id=a.decision_id JOIN workshop_authorization_requests q ON q.id=d.request_id WHERE to_jsonb(o)->>field=NEW.id::text AND d.decision='approved' AND q.type='rectification' AND q.content->>'fact_table'=TG_TABLE_NAME AND q.content->>'fact_id'=to_jsonb(NEW)->>previous) THEN RAISE EXCEPTION 'Rectificación histórica sin autorización explícita' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
CREATE FUNCTION workshop_execution_operation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target jsonb; expected uuid; is_admin boolean;
BEGIN
 IF NEW.operation_type NOT IN ('activity_record','activity_revise','time_record','time_revise','order_finish','order_close') THEN
  IF num_nonnulls(NEW.activity_id,NEW.time_event_id)<>0 THEN RAISE EXCEPTION 'Destino ejecución inesperado' USING ERRCODE='23514'; END IF;
  RETURN NULL;
 END IF;
 PERFORM workshop_require_actor(NEW.actor_id,CASE WHEN NEW.operation_type='order_close' THEN ARRAY['administrator'] ELSE ARRAY['administrator','mechanic'] END);
 IF NEW.work_order_id IS NULL OR num_nonnulls(NEW.report_id,NEW.admission_id,NEW.fleet_asset_id,NEW.report_link_id,NEW.mechanic_release_id,NEW.diagnosis_id,NEW.order_admission_link_id,NEW.scope_id,NEW.authorization_request_id,NEW.authorization_decision_id)<>0 THEN RAISE EXCEPTION 'Destino ejecución incompatible' USING ERRCODE='23514'; END IF;
 IF NEW.operation_type LIKE 'activity_%' THEN
  SELECT to_jsonb(a),s.work_order_id INTO target,expected FROM workshop_activities a JOIN workshop_scope_tasks t ON t.id=a.task_id JOIN workshop_scopes s ON s.id=t.scope_id WHERE a.id=NEW.activity_id;
  IF NEW.time_event_id IS NOT NULL OR NEW.mechanic_assignment_id IS NOT NULL OR (NEW.operation_type='activity_record') IS DISTINCT FROM (target->>'previous_activity_id' IS NULL) THEN RAISE EXCEPTION 'Tipo/destino actividad incompatible' USING ERRCODE='23514'; END IF;
 ELSIF NEW.operation_type LIKE 'time_%' THEN
  SELECT to_jsonb(e),e.work_order_id INTO target,expected FROM workshop_time_events e WHERE e.id=NEW.time_event_id;
  IF NEW.activity_id IS NOT NULL OR NEW.mechanic_assignment_id IS NOT NULL OR (NEW.operation_type='time_record') IS DISTINCT FROM (target->>'previous_event_id' IS NULL) THEN RAISE EXCEPTION 'Tipo/destino tiempo incompatible' USING ERRCODE='23514'; END IF;
 ELSE
  IF num_nonnulls(NEW.activity_id,NEW.time_event_id)<>0 OR NEW.to_version IS NULL THEN RAISE EXCEPTION 'Transición sin versión' USING ERRCODE='23514'; END IF;
  IF NEW.operation_type<>'order_close' AND NOT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=NEW.actor_id AND r.name='administrator') AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.work_order_id=NEW.work_order_id AND e.user_id=NEW.actor_id AND e.is_active AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_releases x WHERE x.assignment_id=a.id)) THEN RAISE EXCEPTION 'Participación requerida' USING ERRCODE='23514'; END IF;

  IF NEW.operation_type='order_close' AND NOT (NEW.confirmed_result ? 'review') THEN RAISE EXCEPTION 'Cierre sin revisión administrativa' USING ERRCODE='23514'; END IF;
  RETURN NULL;
 END IF;
 IF target IS NULL OR expected IS DISTINCT FROM NEW.work_order_id OR (target->>'actor_id')::bigint IS DISTINCT FROM NEW.actor_id THEN RAISE EXCEPTION 'Hecho ejecución incompatible' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
SQL);
        foreach (['workshop_activities', 'workshop_time_events'] as $table) {
            DB::statement("CREATE TRIGGER workshop_lock BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock()");
            DB::statement("CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history()");
            DB::statement("CREATE TRIGGER workshop_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION workshop_preserve_history()");
            DB::statement("CREATE TRIGGER workshop_execution_record BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_execution_record()");
            DB::statement("CREATE CONSTRAINT TRIGGER workshop_execution_audit AFTER INSERT ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_execution_audit()");
        }
        // Preserve the approved phase 2 guards and extend only the three execution transitions.
        $definition = DB::selectOne("SELECT pg_get_functiondef('workshop_phase2_order()'::regprocedure) AS definition")->definition;
        $definition = str_replace('IF NEW.status<>OLD.status AND NOT (', "IF NEW.status<>OLD.status AND NOT ((OLD.status='authorized' AND NEW.status='in_repair') OR (OLD.status='in_repair' AND NEW.status='finished') OR (OLD.status='finished' AND NEW.status='closed') OR ", $definition);
        DB::unprepared($definition);
        $definition = DB::selectOne("SELECT pg_get_functiondef('workshop_phase2_order_audit()'::regprocedure) AS definition")->definition;
        $definition = str_replace("IF NEW.status='pending_authorization' AND OLD.status='pending_diagnosis' THEN", "IF (OLD.status='authorized' AND NEW.status='in_repair') OR (OLD.status='in_repair' AND NEW.status='finished') OR (OLD.status='finished' AND NEW.status='closed') THEN\n IF (NEW.status='in_repair' AND op.operation_type NOT IN ('activity_record','time_record')) OR op.operation_type IS DISTINCT FROM (CASE NEW.status WHEN 'in_repair' THEN op.operation_type WHEN 'finished' THEN 'order_finish' WHEN 'closed' THEN 'order_close' END) THEN RAISE EXCEPTION 'Transición ejecución sin recibo' USING ERRCODE='23514'; END IF;\n ELSIF NEW.status='pending_authorization' AND OLD.status='pending_diagnosis' THEN", $definition);
        DB::unprepared($definition);
        $types = "'activity_record','activity_revise','time_record','time_revise','order_finish','order_close'";
        foreach (['workshop_operation', 'workshop_actor', 'workshop_phase2_operation'] as $trigger) {
            $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS definition FROM pg_trigger WHERE tgrelid='workshop_operations'::regclass AND tgname=?", [$trigger])->definition;
            DB::statement("DROP TRIGGER {$trigger} ON workshop_operations");
            if (str_contains($definition, ' WHEN ')) {
                $definition = preg_replace('/ WHEN \((.*)\) EXECUTE FUNCTION /', ' WHEN (($1) AND NEW.operation_type NOT IN ('.$types.')) EXECUTE FUNCTION ', $definition);
            } else {
                $definition = str_replace(' EXECUTE FUNCTION ', ' WHEN (NEW.operation_type NOT IN ('.$types.')) EXECUTE FUNCTION ', $definition);
            }
            DB::unprepared($definition);
        }
        DB::statement('CREATE CONSTRAINT TRIGGER workshop_phase4_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_execution_operation()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER workshop_phase4_operation ON workshop_operations');
        $types = "'activity_record','activity_revise','time_record','time_revise','order_finish','order_close'";
        foreach (['workshop_operation', 'workshop_actor', 'workshop_phase2_operation'] as $trigger) {
            $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS definition FROM pg_trigger WHERE tgrelid='workshop_operations'::regclass AND tgname=?", [$trigger])->definition;
            DB::statement("DROP TRIGGER {$trigger} ON workshop_operations");
            // pg_get_triggerdef normalizes NOT IN to <> ALL. Restore phase 3 predicates explicitly.
            $phase3 = "'admission_create','admission_exit','historical_observe','historical_resolve','impediment_record','impediment_resolve','warning_note','order_admission_link_create','order_admission_link_invalidate'";
            $phase2 = "'scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen'";
            if ($trigger === 'workshop_phase2_operation') {
                DB::statement('CREATE CONSTRAINT TRIGGER workshop_phase2_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_operation_integrity()');
            } elseif ($trigger === 'workshop_actor') {
                DB::statement("CREATE TRIGGER workshop_actor BEFORE INSERT ON workshop_operations FOR EACH ROW WHEN (NEW.operation_type NOT IN ({$phase2},{$phase3})) EXECUTE FUNCTION workshop_operation_actor()");
            } else {
                DB::statement("CREATE CONSTRAINT TRIGGER workshop_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation_type NOT IN ({$phase2},{$phase3})) EXECUTE FUNCTION workshop_operation_integrity_core()");
            }
        }
        $source = file_get_contents(__DIR__.'/2026_10_12_000210_protect_workshop_authorizations.php');
        foreach (['workshop_phase2_order', 'workshop_phase2_order_audit'] as $function) {
            preg_match('/CREATE FUNCTION '.preg_quote($function, '/').'\\(\\) RETURNS trigger.*?END; \\$\\$;/s', $source, $match);
            DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $match[0]));
        }
        foreach (['workshop_activities', 'workshop_time_events'] as $table) {
            foreach (['workshop_lock', 'workshop_history', 'workshop_truncate', 'workshop_execution_record', 'workshop_execution_audit'] as $trigger) {
                DB::statement("DROP TRIGGER {$trigger} ON {$table}");
            }
        }
        DB::statement('DROP FUNCTION workshop_execution_operation(),workshop_execution_audit(),workshop_execution_record(),workshop_execution_actor(bigint,uuid,uuid,timestamptz),workshop_scope_is_effective(uuid)');
    }
};
