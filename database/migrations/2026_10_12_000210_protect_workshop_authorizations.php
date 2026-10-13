<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION workshop_scope_content(target uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('work_order_id',s.work_order_id,'scope_id',s.id,'root_record_id',s.root_record_id,
 'revision',s.revision,'diagnosis_id',s.diagnosis_id,'scope_kind',s.scope_kind,'description',s.description,
 'tasks',COALESCE((SELECT jsonb_agg(jsonb_build_object('id',t.id,'line_number',t.line_number,'description',t.description,'supplier_id',t.supplier_id) ORDER BY t.line_number) FROM workshop_scope_tasks t WHERE t.scope_id=s.id),'[]'::jsonb),
 'parts',COALESCE((SELECT jsonb_agg(jsonb_build_object('id',p.id,'line_number',p.line_number,'task_id',p.task_id,'spare_part_id',p.spare_part_id,'quantity',p.quantity::text,'unit_snapshot',p.unit_snapshot,'description_snapshot',p.description_snapshot) ORDER BY p.line_number) FROM workshop_scope_parts p WHERE p.scope_id=s.id),'[]'::jsonb))
 FROM workshop_scopes s WHERE s.id=target;
$$;
CREATE FUNCTION workshop_exact_quantity(value text) RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
 IF value IS NULL OR value !~ '^[+-]?[0-9]+(\.[0-9]{1,6})?$' OR length(value)>4096 THEN
  RAISE EXCEPTION 'Cantidad decimal exacta inválida' USING ERRCODE='23514';
 END IF;
 IF value::numeric<=0 OR value::numeric>=1000000000000 THEN RAISE EXCEPTION 'Cantidad fuera de rango' USING ERRCODE='23514'; END IF;
 RETURN value::numeric;
END; $$;
CREATE FUNCTION workshop_write_scope_part(part_id uuid, target_scope uuid, target_task uuid, catalog_part uuid, line integer, value text) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE item public.spare_parts;
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 SELECT * INTO STRICT item FROM public.spare_parts WHERE id=catalog_part FOR SHARE;
 INSERT INTO public.workshop_scope_parts(id,scope_id,task_id,spare_part_id,line_number,quantity,unit_snapshot,description_snapshot)
 VALUES(part_id,target_scope,target_task,catalog_part,line,public.workshop_exact_quantity(value),item.unit,item.description);
 RETURN part_id;
END; $$;
REVOKE ALL ON FUNCTION workshop_write_scope_part(uuid,uuid,uuid,uuid,integer,text) FROM PUBLIC;
CREATE FUNCTION workshop_authorization_record() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE scope workshop_scopes; prior workshop_scopes; req workshop_authorization_requests; old_req workshop_authorization_requests;
BEGIN
 IF TG_TABLE_NAME='workshop_scopes' THEN
  PERFORM workshop_require_actor(NEW.proposed_by_id,ARRAY['administrator','secretary','mechanic']);
  PERFORM 1 FROM workshop_work_orders WHERE id=NEW.work_order_id FOR UPDATE;
  IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=NEW.proposed_by_id AND r.name IN ('administrator','secretary'))
   AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.work_order_id=NEW.work_order_id AND e.user_id=NEW.proposed_by_id AND e.is_active AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_releases x WHERE x.assignment_id=a.id)) THEN
   RAISE EXCEPTION 'Mecánico sin participación vigente' USING ERRCODE='23514'; END IF;
  IF NEW.previous_scope_id IS NOT NULL THEN
   SELECT * INTO prior FROM workshop_scopes WHERE id=NEW.previous_scope_id FOR UPDATE;
   IF prior.id IS NULL OR prior.work_order_id<>NEW.work_order_id OR prior.root_record_id<>NEW.root_record_id OR prior.revision+1<>NEW.revision THEN
    RAISE EXCEPTION 'Cadena de alcance incompatible' USING ERRCODE='23514'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME IN ('workshop_scope_tasks','workshop_scope_parts') THEN
  SELECT * INTO scope FROM workshop_scopes WHERE id=NEW.scope_id FOR UPDATE;
  IF EXISTS(SELECT 1 FROM workshop_authorization_requests WHERE scope_id=NEW.scope_id) THEN
   RAISE EXCEPTION 'Líneas de alcance solicitado inmutables' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='workshop_scope_parts' THEN
  IF NOT EXISTS(SELECT 1 FROM spare_parts p WHERE p.id=NEW.spare_part_id AND p.unit=NEW.unit_snapshot AND p.description=NEW.description_snapshot) THEN
   RAISE EXCEPTION 'Snapshot de catálogo incompatible' USING ERRCODE='23514'; END IF;
  IF scope.previous_scope_id IS NOT NULL AND EXISTS(
   SELECT 1 FROM workshop_scope_parts p WHERE p.scope_id=scope.previous_scope_id AND p.line_number=NEW.line_number AND (p.spare_part_id<>NEW.spare_part_id OR p.unit_snapshot<>NEW.unit_snapshot)) THEN
   RAISE EXCEPTION 'Identidad de línea histórica incompatible' USING ERRCODE='23514'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='workshop_authorization_requests' THEN
  PERFORM workshop_require_actor(NEW.requested_by_id,CASE WHEN NEW.type='exit' THEN ARRAY['administrator'] WHEN NEW.type IN ('repair','extension') OR (NEW.type='rectification' AND NEW.scope_id IS NOT NULL) THEN ARRAY['administrator','secretary','mechanic'] ELSE ARRAY['administrator','secretary'] END);
  IF NEW.work_order_id IS NOT NULL THEN PERFORM 1 FROM workshop_work_orders WHERE id=NEW.work_order_id FOR UPDATE; ELSE PERFORM 1 FROM workshop_admissions WHERE id=NEW.admission_id FOR UPDATE; END IF;
  IF (NEW.type IN ('repair','extension') OR (NEW.type='rectification' AND NEW.scope_id IS NOT NULL)) AND NOT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=NEW.requested_by_id AND r.name IN ('administrator','secretary')) AND NOT EXISTS(
   SELECT 1 FROM workshop_mechanic_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.work_order_id=NEW.work_order_id AND e.user_id=NEW.requested_by_id AND e.is_active AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_releases x WHERE x.assignment_id=a.id)) THEN
   RAISE EXCEPTION 'Solicitante sin participación vigente' USING ERRCODE='23514'; END IF;
  IF NEW.type IN ('repair','extension') AND (SELECT status FROM workshop_work_orders WHERE id=NEW.work_order_id) IN ('finished','closed','cancelled') THEN RAISE EXCEPTION 'Orden terminal requiere reapertura antes de nuevo alcance' USING ERRCODE='23514'; END IF;
  IF NEW.scope_id IS NOT NULL THEN
   SELECT * INTO scope FROM workshop_scopes WHERE id=NEW.scope_id FOR UPDATE;
   IF scope.id IS NULL OR scope.work_order_id IS DISTINCT FROM NEW.work_order_id OR
    (NEW.type='repair' AND scope.scope_kind<>'initial') OR (NEW.type='extension' AND scope.scope_kind<>'extension') OR (NEW.type='rectification' AND scope.scope_kind<>'revision') THEN
    RAISE EXCEPTION 'Tipo/ámbito de alcance incompatible' USING ERRCODE='23514'; END IF;
   IF NEW.content IS DISTINCT FROM workshop_scope_content(NEW.scope_id) OR NOT EXISTS(SELECT 1 FROM workshop_scope_tasks WHERE scope_id=NEW.scope_id) THEN
    RAISE EXCEPTION 'Contenido de alcance no corresponde o sin tareas' USING ERRCODE='23514'; END IF;
  ELSE
   IF NEW.target_version IS DISTINCT FROM (CASE WHEN NEW.work_order_id IS NOT NULL THEN (SELECT version FROM workshop_work_orders WHERE id=NEW.work_order_id) ELSE (SELECT version FROM workshop_admissions WHERE id=NEW.admission_id) END) THEN
    RAISE EXCEPTION 'Versión solicitada obsoleta' USING ERRCODE='23514'; END IF;
  END IF;
  IF NEW.scope_id IS NOT NULL AND NEW.type='rectification' AND NEW.target_version IS DISTINCT FROM (SELECT version FROM workshop_work_orders WHERE id=NEW.work_order_id) THEN RAISE EXCEPTION 'Versión de alcance obsoleta' USING ERRCODE='23514'; END IF;
  IF NEW.scope_id IS NULL AND NEW.work_order_id IS NOT NULL THEN
   IF NEW.content->'before' IS DISTINCT FROM (SELECT to_jsonb(w) FROM workshop_work_orders w WHERE id=NEW.work_order_id) OR jsonb_typeof(NEW.content->'after') IS DISTINCT FROM 'object' OR NEW.content->'after'->'version' IS DISTINCT FROM to_jsonb(NEW.target_version+1) OR ((NEW.content->'before')-ARRAY['description','priority','status','version']) IS DISTINCT FROM ((NEW.content->'after')-ARRAY['description','priority','status','version']) THEN
    RAISE EXCEPTION 'Contenido de cambio de orden incompatible' USING ERRCODE='23514'; END IF;
  END IF;
  IF NEW.previous_request_id IS NOT NULL THEN
   SELECT * INTO old_req FROM workshop_authorization_requests WHERE id=NEW.previous_request_id FOR UPDATE;
   IF old_req.id IS NULL OR old_req.type<>NEW.type OR old_req.work_order_id IS DISTINCT FROM NEW.work_order_id OR old_req.admission_id IS DISTINCT FROM NEW.admission_id OR NOT EXISTS(SELECT 1 FROM workshop_authorization_decisions WHERE request_id=old_req.id AND decision='rejected') THEN
    RAISE EXCEPTION 'Predecesor de solicitud incompatible o no rechazado' USING ERRCODE='23514'; END IF;
  END IF;
  IF EXISTS(SELECT 1 FROM workshop_authorization_requests q WHERE q.type=NEW.type AND q.work_order_id IS NOT DISTINCT FROM NEW.work_order_id AND q.admission_id IS NOT DISTINCT FROM NEW.admission_id AND q.scope_id IS NOT DISTINCT FROM NEW.scope_id AND q.target_version IS NOT DISTINCT FROM NEW.target_version AND NOT EXISTS(SELECT 1 FROM workshop_authorization_decisions d WHERE d.request_id=q.id AND d.decision='rejected')) THEN
   RAISE EXCEPTION 'Solicitud duplicada pendiente o aprobada' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='workshop_authorization_decisions' THEN
  PERFORM workshop_require_actor(NEW.decided_by_id,ARRAY['administrator']);
  PERFORM 1 FROM workshop_authorization_requests WHERE id=NEW.request_id FOR UPDATE;
 ELSIF TG_TABLE_NAME='workshop_authorization_applications' THEN
  PERFORM workshop_require_actor(NEW.applied_by_id,ARRAY['administrator']);
  PERFORM 1 FROM workshop_authorization_decisions WHERE id=NEW.decision_id FOR UPDATE;
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION workshop_authorization_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE total integer; req workshop_authorization_requests; decision workshop_authorization_decisions; op workshop_operations; expected_status text;
BEGIN
 IF TG_TABLE_NAME='workshop_scopes' THEN
  SELECT count(*) INTO total FROM workshop_authorization_requests WHERE scope_id=NEW.id;
 ELSIF TG_TABLE_NAME='workshop_authorization_requests' THEN
  SELECT count(*) INTO total FROM workshop_operations WHERE authorization_request_id=NEW.id AND operation_type IN ('scope_request','authorization_request') AND actor_id=NEW.requested_by_id;
 ELSIF TG_TABLE_NAME='workshop_authorization_decisions' THEN
  SELECT count(*) INTO total FROM workshop_operations WHERE authorization_decision_id=NEW.id AND operation_type='authorization_decide' AND actor_id=NEW.decided_by_id;
 ELSE
  SELECT * INTO decision FROM workshop_authorization_decisions WHERE id=NEW.decision_id;
  SELECT * INTO req FROM workshop_authorization_requests WHERE id=decision.request_id;
  SELECT * INTO op FROM workshop_operations WHERE id=NEW.operation_id;
  IF decision.decision<>'approved' OR op.id IS NULL OR op.actor_id<>NEW.applied_by_id OR op.work_order_id IS DISTINCT FROM req.work_order_id OR op.admission_id IS DISTINCT FROM req.admission_id THEN
   RAISE EXCEPTION 'Aplicación no aprobada o ámbito incompatible' USING ERRCODE='23514'; END IF;
  IF req.scope_id IS NOT NULL THEN
   IF req.type IN ('repair','extension') AND (SELECT status FROM workshop_work_orders WHERE id=req.work_order_id) IN ('finished','closed','cancelled') THEN RAISE EXCEPTION 'Orden terminal requiere reapertura antes de aplicar alcance' USING ERRCODE='23514'; END IF;
   IF req.type='repair' AND ((SELECT status FROM workshop_work_orders WHERE id=req.work_order_id)<>'authorized' OR op.before_values->>'status' IS DISTINCT FROM 'pending_authorization' OR op.after_values->>'status' IS DISTINCT FROM 'authorized') THEN RAISE EXCEPTION 'Aplicación inicial sin transición autorizada' USING ERRCODE='23514'; END IF;
   IF req.type='rectification' AND req.target_version IS DISTINCT FROM COALESCE(op.from_version,(SELECT version FROM workshop_work_orders WHERE id=req.work_order_id)) THEN RAISE EXCEPTION 'Versión de rectificación obsoleta' USING ERRCODE='23514'; END IF;
   IF EXISTS(SELECT 1 FROM workshop_authorization_applications a JOIN workshop_authorization_decisions d ON d.id=a.decision_id JOIN workshop_authorization_requests q ON q.id=d.request_id JOIN workshop_scopes s ON s.id=q.scope_id JOIN workshop_scopes proposed ON proposed.id=req.scope_id WHERE a.id<>NEW.id AND s.root_record_id=proposed.root_record_id AND s.revision>=proposed.revision) THEN RAISE EXCEPTION 'Alcance anterior o ya aplicado' USING ERRCODE='23514'; END IF;
   IF op.operation_type<>'scope_apply' OR op.scope_id IS DISTINCT FROM req.scope_id OR req.content IS DISTINCT FROM workshop_scope_content(req.scope_id) THEN RAISE EXCEPTION 'Aplicación de alcance distinta del aprobado' USING ERRCODE='23514'; END IF;
  ELSE
   IF req.type='cancellation' AND (op.before_values->>'status' NOT IN ('pending_diagnosis','pending_authorization','authorized','in_repair') OR op.after_values->>'status' IS DISTINCT FROM 'cancelled') THEN RAISE EXCEPTION 'Cancelación inválida' USING ERRCODE='23514'; END IF;
   IF req.type='reopening' THEN
    expected_status=CASE WHEN EXISTS(SELECT 1 FROM workshop_authorization_applications a JOIN workshop_authorization_decisions d ON d.id=a.decision_id JOIN workshop_authorization_requests q ON q.id=d.request_id WHERE q.work_order_id=req.work_order_id AND q.scope_id IS NOT NULL) THEN 'authorized' WHEN EXISTS(SELECT 1 FROM workshop_diagnoses WHERE work_order_id=req.work_order_id) THEN 'pending_authorization' ELSE 'pending_diagnosis' END;
    IF op.before_values->>'status' NOT IN ('finished','closed','cancelled') OR op.after_values->>'status' IS DISTINCT FROM expected_status THEN RAISE EXCEPTION 'Destino reapertura incompatible' USING ERRCODE='23514'; END IF;
   END IF;
   IF op.operation_type IS DISTINCT FROM (CASE req.type WHEN 'exit' THEN 'admission_exit' WHEN 'reopening' THEN 'order_reopen' WHEN 'cancellation' THEN 'order_cancel' WHEN 'rectification' THEN CASE WHEN req.admission_id IS NULL THEN 'order_update' ELSE 'admission_rectify' END END)
    OR op.from_version IS DISTINCT FROM req.target_version OR op.after_values IS DISTINCT FROM req.content->'after' THEN
    RAISE EXCEPTION 'Aplicación de versión/contenido no autorizada' USING ERRCODE='23514'; END IF;
  END IF;
  RETURN NULL;
 END IF;
 IF total<>1 THEN RAISE EXCEPTION 'Hecho autorización sin correlación única' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
ALTER FUNCTION workshop_operation_integrity() RENAME TO workshop_operation_integrity_core;
CREATE FUNCTION workshop_operation_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE req workshop_authorization_requests; decision workshop_authorization_decisions;
BEGIN
 IF NEW.operation_type NOT IN ('scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen') THEN
  IF num_nonnulls(NEW.scope_id,NEW.authorization_request_id,NEW.authorization_decision_id)<>0 THEN RAISE EXCEPTION 'Destino autorización inesperado' USING ERRCODE='23514'; END IF;
  RETURN NULL;
 END IF;
 IF num_nonnulls(NEW.report_link_id,NEW.mechanic_assignment_id,NEW.mechanic_release_id,NEW.diagnosis_id,NEW.order_admission_link_id)<>0 THEN RAISE EXCEPTION 'Destino adicional incompatible' USING ERRCODE='23514'; END IF;
 IF NEW.operation_type IN ('scope_request','authorization_request','authorization_decide') THEN
  SELECT * INTO req FROM workshop_authorization_requests WHERE id=NEW.authorization_request_id;
  IF req.id IS NULL OR req.work_order_id IS DISTINCT FROM NEW.work_order_id OR req.admission_id IS DISTINCT FROM NEW.admission_id OR NEW.scope_id IS DISTINCT FROM req.scope_id OR (NEW.from_version IS NOT NULL AND NEW.operation_type<>'scope_request') THEN RAISE EXCEPTION 'Operación de otra solicitud' USING ERRCODE='23514'; END IF;
  IF NEW.operation_type='authorization_decide' THEN
   SELECT * INTO decision FROM workshop_authorization_decisions WHERE id=NEW.authorization_decision_id;
   IF decision.request_id IS DISTINCT FROM req.id OR decision.decided_by_id IS DISTINCT FROM NEW.actor_id THEN RAISE EXCEPTION 'Decisión de otra solicitud/actor' USING ERRCODE='23514'; END IF;
  ELSIF NEW.authorization_decision_id IS NOT NULL OR req.requested_by_id<>NEW.actor_id THEN RAISE EXCEPTION 'Actor/destino solicitud incompatible' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.authorization_request_id IS NOT NULL OR NEW.authorization_decision_id IS NOT NULL OR (NEW.operation_type='scope_apply' AND NEW.scope_id IS NULL) OR NOT EXISTS(SELECT 1 FROM workshop_authorization_applications WHERE operation_id=NEW.id) THEN RAISE EXCEPTION 'Operación sin aplicación' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NULL;
END; $$;
DROP TRIGGER workshop_operation ON workshop_operations;
CREATE CONSTRAINT TRIGGER workshop_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation_type NOT IN ('scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen')) EXECUTE FUNCTION workshop_operation_integrity_core();
CREATE CONSTRAINT TRIGGER workshop_phase2_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_operation_integrity();
CREATE FUNCTION workshop_phase2_actor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.operation_type IN ('authorization_decide','scope_apply','order_cancel','order_reopen') THEN PERFORM workshop_require_actor(NEW.actor_id,ARRAY['administrator']);
 ELSIF NEW.operation_type IN ('scope_request','authorization_request') THEN PERFORM workshop_require_actor(NEW.actor_id,ARRAY['administrator','secretary','mechanic']); END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER workshop_phase2_actor BEFORE INSERT ON workshop_operations FOR EACH ROW EXECUTE FUNCTION workshop_phase2_actor();

DROP TRIGGER workshop_actor ON workshop_operations;
CREATE TRIGGER workshop_actor BEFORE INSERT ON workshop_operations FOR EACH ROW WHEN (NEW.operation_type NOT IN ('scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen')) EXECUTE FUNCTION workshop_operation_actor();
CREATE FUNCTION workshop_phase2_order() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  PERFORM workshop_require_actor(NEW.created_by_id,ARRAY['administrator','secretary']);
  IF NEW.status<>'pending_diagnosis' OR NEW.version<>1 THEN RAISE EXCEPTION 'Estado inicial inválido' USING ERRCODE='23514'; END IF;
 ELSE
  IF (to_jsonb(NEW)-ARRAY['description','priority','status','version']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['description','priority','status','version']) OR NEW.version<>OLD.version+1 THEN RAISE EXCEPTION 'Cambio/versión inválido' USING ERRCODE='23514'; END IF;
  IF NEW.status<>OLD.status AND NOT ((OLD.status='pending_diagnosis' AND NEW.status='pending_authorization') OR (OLD.status='pending_authorization' AND NEW.status='authorized') OR (OLD.status IN ('pending_diagnosis','pending_authorization','authorized','in_repair') AND NEW.status='cancelled') OR (OLD.status IN ('finished','closed','cancelled') AND NEW.status IN ('pending_diagnosis','pending_authorization','authorized'))) THEN
   RAISE EXCEPTION 'Transición no habilitada en esta fase' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION workshop_phase2_order_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE op workshop_operations; req workshop_authorization_requests;
BEGIN
 IF TG_OP='INSERT' THEN
  IF (SELECT count(*) FROM workshop_operations WHERE work_order_id=NEW.id AND operation_type='order_create' AND actor_id=NEW.created_by_id)<>1 THEN RAISE EXCEPTION 'Orden sin creación auditada' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO op FROM workshop_operations WHERE work_order_id=NEW.id AND from_version=OLD.version AND to_version=NEW.version;
  IF op.id IS NULL OR op.before_values IS DISTINCT FROM to_jsonb(OLD) OR op.after_values IS DISTINCT FROM to_jsonb(NEW) THEN RAISE EXCEPTION 'Cambio sin snapshots exactos' USING ERRCODE='23514'; END IF;
  IF NEW.status<>OLD.status THEN
   IF NEW.status='pending_authorization' AND OLD.status='pending_diagnosis' THEN
    IF op.operation_type<>'scope_request' OR NOT EXISTS(SELECT 1 FROM workshop_authorization_requests WHERE id=op.authorization_request_id AND type='repair' AND work_order_id=NEW.id) THEN RAISE EXCEPTION 'Transición sin solicitud inicial' USING ERRCODE='23514'; END IF;
   ELSIF NEW.status='authorized' AND OLD.status='pending_authorization' THEN
    IF op.operation_type<>'scope_apply' THEN RAISE EXCEPTION 'Transición sin aplicación' USING ERRCODE='23514'; END IF;
   ELSIF NEW.status='cancelled' THEN
    IF op.operation_type<>'order_cancel' THEN RAISE EXCEPTION 'Cancelación sin aprobación' USING ERRCODE='23514'; END IF;
   ELSE
    IF op.operation_type<>'order_reopen' THEN RAISE EXCEPTION 'Reapertura sin aprobación' USING ERRCODE='23514'; END IF;
   END IF;
  ELSIF op.operation_type NOT IN ('order_update','scope_apply') THEN RAISE EXCEPTION 'Cambio sin operación compatible' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NULL;
END; $$;
DROP TRIGGER workshop_record ON workshop_work_orders;
DROP TRIGGER workshop_audit ON workshop_work_orders;
CREATE TRIGGER workshop_record BEFORE INSERT OR UPDATE ON workshop_work_orders FOR EACH ROW EXECUTE FUNCTION workshop_phase2_order();
CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT OR UPDATE ON workshop_work_orders DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_phase2_order_audit();
SQL);
        foreach (['workshop_scopes', 'workshop_scope_tasks', 'workshop_scope_parts', 'workshop_authorization_requests', 'workshop_authorization_decisions', 'workshop_authorization_applications'] as $table) {
            DB::statement("CREATE TRIGGER workshop_lock BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock()");
            DB::statement("CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history()");
            DB::statement("CREATE TRIGGER workshop_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION workshop_preserve_history()");
            DB::statement("CREATE TRIGGER workshop_record BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_authorization_record()");
            if (! in_array($table, ['workshop_scope_tasks', 'workshop_scope_parts'], true)) {
                DB::statement("CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_authorization_audit()");
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER workshop_record ON workshop_work_orders');
        DB::statement('DROP TRIGGER workshop_audit ON workshop_work_orders');
        DB::statement('CREATE TRIGGER workshop_record BEFORE INSERT OR UPDATE ON workshop_work_orders FOR EACH ROW EXECUTE FUNCTION workshop_core_record()');
        DB::statement('CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT OR UPDATE ON workshop_work_orders DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_fact_audit()');
        DB::statement('DROP FUNCTION workshop_phase2_order()');
        DB::statement('DROP FUNCTION workshop_phase2_order_audit()');
        DB::statement('DROP TRIGGER workshop_actor ON workshop_operations');
        DB::statement('CREATE TRIGGER workshop_actor BEFORE INSERT ON workshop_operations FOR EACH ROW EXECUTE FUNCTION workshop_operation_actor()');
        foreach (['workshop_scopes', 'workshop_scope_tasks', 'workshop_scope_parts', 'workshop_authorization_requests', 'workshop_authorization_decisions', 'workshop_authorization_applications'] as $table) {
            foreach (['workshop_audit', 'workshop_record', 'workshop_no_truncate', 'workshop_history', 'workshop_lock'] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
            }
        }
        DB::statement('DROP TRIGGER workshop_phase2_actor ON workshop_operations');
        DB::statement('DROP TRIGGER workshop_phase2_operation ON workshop_operations');
        DB::statement('DROP TRIGGER workshop_operation ON workshop_operations');
        DB::statement('DROP FUNCTION workshop_operation_integrity()');
        DB::statement('ALTER FUNCTION workshop_operation_integrity_core() RENAME TO workshop_operation_integrity');
        DB::statement('CREATE CONSTRAINT TRIGGER workshop_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_operation_integrity()');
        foreach (['workshop_phase2_actor()', 'workshop_authorization_audit()', 'workshop_authorization_record()', 'workshop_write_scope_part(uuid,uuid,uuid,uuid,integer,text)', 'workshop_exact_quantity(text)', 'workshop_scope_content(uuid)'] as $function) {
            DB::statement("DROP FUNCTION {$function}");
        }
    }
};
