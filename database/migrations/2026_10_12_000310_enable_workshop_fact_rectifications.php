<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION workshop_fact_values(target_table text, value jsonb) RETURNS jsonb LANGUAGE plpgsql STABLE AS $$
DECLARE result jsonb; field text;
BEGIN
 result=value-ARRAY['id','work_order_id','registered_at','root_record_id','revision','actor_id','recorded_by_id','confirmed_by_id','requested_by_id','uploaded_by_id','previous_activity_id','previous_event_id','previous_delivery_id','previous_usage_id','previous_record_id','previous_cost_id','previous_need_id','previous_attachment_id'];
 IF target_table='workshop_attachments' THEN
  result=jsonb_build_object('category',value->'category','external_repair_id',value->'external_repair_id','mime',value->'mime','extension',value->'extension','size_bytes',value->'size_bytes','checksum',value->'checksum');
 ELSE
  FOREACH field IN ARRAY ARRAY['quantity','amount'] LOOP
   IF result ? field AND result->>field IS NOT NULL THEN
    result=jsonb_set(result,ARRAY[field],to_jsonb(trim_scale(CASE WHEN field='quantity' THEN workshop_exact_quantity(result->>field) ELSE workshop_exact_cost(result->>field) END)::text));
   END IF;
  END LOOP;
  FOREACH field IN ARRAY ARRAY['occurred_at','performed_at'] LOOP
   IF result ? field THEN result=jsonb_set(result,ARRAY[field],to_jsonb((result->>field)::timestamptz)); END IF;
  END LOOP;
 END IF;
 RETURN result;
END; $$;
CREATE FUNCTION workshop_fact_context(target_table text, target_id uuid) RETURNS jsonb LANGUAGE plpgsql AS $$
DECLARE fact jsonb; order_id uuid;
BEGIN
 IF target_table NOT IN ('workshop_activities','workshop_time_events','workshop_part_deliveries','workshop_part_usages','workshop_external_repairs','workshop_cost_records','workshop_material_needs','workshop_attachments') THEN RAISE EXCEPTION 'Destino de rectificación no permitido' USING ERRCODE='23514'; END IF;
 EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id=$1',target_table) INTO fact USING target_id;
 IF fact IS NULL THEN RAISE EXCEPTION 'Hecho de rectificación inexistente' USING ERRCODE='23514'; END IF;
 IF target_table='workshop_activities' THEN SELECT s.work_order_id INTO order_id FROM workshop_scope_tasks t JOIN workshop_scopes s ON s.id=t.scope_id WHERE t.id=(fact->>'task_id')::uuid;
 ELSIF target_table='workshop_part_deliveries' THEN SELECT s.work_order_id INTO order_id FROM workshop_scope_parts p JOIN workshop_scopes s ON s.id=p.scope_id WHERE p.id=(fact->>'scope_part_id')::uuid;
 ELSIF target_table='workshop_part_usages' THEN SELECT s.work_order_id INTO order_id FROM workshop_part_deliveries d JOIN workshop_scope_parts p ON p.id=d.scope_part_id JOIN workshop_scopes s ON s.id=p.scope_id WHERE d.id=(fact->>'delivery_id')::uuid;
 ELSE order_id=(fact->>'work_order_id')::uuid; END IF;
 RETURN jsonb_build_object('work_order_id',order_id,'fact',fact);
END; $$;
CREATE FUNCTION workshop_validate_fact_request(value jsonb) RETURNS void LANGUAGE plpgsql AS $$
DECLARE context jsonb; requested_content jsonb; target uuid; proposed jsonb; field text; predecessor uuid;
BEGIN
 requested_content=value->'content';
 PERFORM workshop_require_actor((value->>'requested_by_id')::bigint,ARRAY['administrator']);
 context=workshop_fact_context(requested_content->>'fact_table',(requested_content->>'fact_id')::uuid);
 target=(context->>'work_order_id')::uuid;
 PERFORM 1 FROM workshop_work_orders WHERE id=target FOR UPDATE;
 IF value->>'work_order_id' IS DISTINCT FROM target::text OR value->>'scope_id' IS NOT NULL OR value->>'admission_id' IS NOT NULL OR (value->>'target_version')::bigint IS DISTINCT FROM (SELECT version FROM workshop_work_orders WHERE id=target)
 OR requested_content->'before' IS DISTINCT FROM context->'fact' OR jsonb_typeof(requested_content->'after') IS DISTINCT FROM 'object' OR requested_content->>'executor_id' IS NULL THEN RAISE EXCEPTION 'Rectificación no corresponde a hecho/versión exactos' USING ERRCODE='23514'; END IF;
 PERFORM workshop_require_actor((requested_content->>'executor_id')::bigint,ARRAY['administrator','mechanic']);
 predecessor=(value->>'previous_request_id')::uuid;
 IF predecessor IS NOT NULL AND NOT EXISTS(SELECT 1 FROM workshop_authorization_requests q JOIN workshop_authorization_decisions d ON d.request_id=q.id WHERE q.id=predecessor AND q.type='rectification' AND q.work_order_id=target AND q.content->>'fact_id'=requested_content->>'fact_id' AND d.decision='rejected') THEN RAISE EXCEPTION 'Predecesor de solicitud inválido' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM workshop_authorization_requests q WHERE q.work_order_id=target AND q.type='rectification' AND q.content->>'fact_id'=requested_content->>'fact_id' AND NOT EXISTS(SELECT 1 FROM workshop_authorization_decisions d WHERE d.request_id=q.id AND d.decision='rejected')) THEN RAISE EXCEPTION 'Rectificación ya solicitada' USING ERRCODE='23514'; END IF;
 -- Retain the raw six-place input until validation, before canonical NUMERIC values.
 proposed=workshop_fact_values(requested_content->>'fact_table',requested_content->'after');
 IF proposed IS NULL THEN RAISE EXCEPTION 'Contenido inválido' USING ERRCODE='23514'; END IF;
END; $$;
CREATE FUNCTION workshop_validate_fact_application(value jsonb) RETURNS void LANGUAGE plpgsql AS $$
DECLARE decision workshop_authorization_decisions; req workshop_authorization_requests; op workshop_operations; context jsonb; fact jsonb; dest text; previous text; expected text; table_name text;
BEGIN
 SELECT * INTO decision FROM workshop_authorization_decisions WHERE id=(value->>'decision_id')::uuid;
 SELECT * INTO req FROM workshop_authorization_requests WHERE id=decision.request_id;
 SELECT * INTO op FROM workshop_operations WHERE id=(value->>'operation_id')::uuid;
 table_name=req.content->>'fact_table';
 CASE table_name
 WHEN 'workshop_activities' THEN dest='activity_id'; previous='previous_activity_id'; expected='activity_revise';
 WHEN 'workshop_time_events' THEN dest='time_event_id'; previous='previous_event_id'; expected='time_revise';
 WHEN 'workshop_part_deliveries' THEN dest='delivery_id'; previous='previous_delivery_id'; expected='delivery_revise';
 WHEN 'workshop_part_usages' THEN dest='usage_id'; previous='previous_usage_id'; expected='usage_revise';
 WHEN 'workshop_external_repairs' THEN dest='external_repair_id'; previous='previous_record_id'; expected='external_revise';
 WHEN 'workshop_cost_records' THEN dest='cost_record_id'; previous='previous_cost_id'; expected=CASE WHEN req.content->'before'->>'cost_kind' IS NULL AND req.content->'after'->>'cost_kind' IS NOT NULL THEN 'cost_classify' ELSE 'cost_revise' END;
 WHEN 'workshop_material_needs' THEN dest='material_need_id'; previous='previous_need_id'; expected='material_need_revise';
 WHEN 'workshop_attachments' THEN dest='attachment_id'; previous='previous_attachment_id'; expected='attachment_confirm';
 ELSE RAISE EXCEPTION 'Destino sensible inválido' USING ERRCODE='23514';
 END CASE;
 context=workshop_fact_context(table_name,(to_jsonb(op)->>dest)::uuid); fact=context->'fact';
 IF decision.decision IS DISTINCT FROM 'approved' OR req.type<>'rectification' OR req.scope_id IS NOT NULL OR op.id IS NULL OR op.operation_type IS DISTINCT FROM expected OR op.work_order_id IS DISTINCT FROM req.work_order_id OR op.admission_id IS NOT NULL OR (context->>'work_order_id')::uuid IS DISTINCT FROM req.work_order_id OR op.actor_id IS DISTINCT FROM (value->>'applied_by_id')::bigint OR op.actor_id IS DISTINCT FROM (req.content->>'executor_id')::bigint
 OR fact->>previous IS DISTINCT FROM req.content->>'fact_id' OR (req.content ? 'planned_record_id' AND fact->>'id' IS DISTINCT FROM req.content->>'planned_record_id') OR req.target_version IS DISTINCT FROM (SELECT version FROM workshop_work_orders WHERE id=req.work_order_id)
 OR req.content->'before' IS DISTINCT FROM (workshop_fact_context(table_name,(req.content->>'fact_id')::uuid)->'fact')
 OR workshop_fact_values(table_name,fact) IS DISTINCT FROM workshop_fact_values(table_name,req.content->'after') THEN RAISE EXCEPTION 'Rectificación distinta de la autorización exacta' USING ERRCODE='23514'; END IF;
 PERFORM workshop_require_actor(op.actor_id,ARRAY['administrator','mechanic']);
END; $$;
SQL);
        // Extend the published functions in a new migration, retaining every old branch.
        foreach (['workshop_authorization_record', 'workshop_authorization_audit_phase2'] as $function) {
            $definition = DB::selectOne('SELECT pg_get_functiondef(?::regproc) AS definition', [$function])->definition;
            if ($function === 'workshop_authorization_record') {
                $branch = <<<'SQL'

 IF TG_TABLE_NAME='workshop_authorization_requests' AND to_jsonb(NEW)->>'type'='rectification' AND (to_jsonb(NEW)->'content') ? 'fact_table' THEN
  PERFORM workshop_validate_fact_request(to_jsonb(NEW)); RETURN NEW;
 END IF;
 IF TG_TABLE_NAME='workshop_authorization_applications' AND EXISTS(SELECT 1 FROM workshop_authorization_decisions d JOIN workshop_authorization_requests q ON q.id=d.request_id WHERE d.id=(to_jsonb(NEW)->>'decision_id')::uuid AND q.type='rectification' AND q.content ? 'fact_table') THEN
  PERFORM workshop_require_actor((to_jsonb(NEW)->>'applied_by_id')::bigint,ARRAY['administrator','mechanic']); RETURN NEW;
 END IF;
SQL;
            } else {
                $branch = <<<'SQL'

 IF TG_TABLE_NAME='workshop_authorization_applications' AND EXISTS(SELECT 1 FROM workshop_authorization_decisions d JOIN workshop_authorization_requests q ON q.id=d.request_id WHERE d.id=(to_jsonb(NEW)->>'decision_id')::uuid AND q.type='rectification' AND q.content ? 'fact_table') THEN
  PERFORM workshop_validate_fact_application(to_jsonb(NEW)); RETURN NULL;
 END IF;
SQL;
            }
            $position = strpos($definition, "BEGIN\n");
            DB::unprepared(substr_replace($definition, "BEGIN\n".$branch, $position, strlen("BEGIN\n")));
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION workshop_sensitive_fact_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous text; dest text; op workshop_operations; needs_approval boolean; context jsonb;
BEGIN
 CASE TG_TABLE_NAME
 WHEN 'workshop_activities' THEN previous='previous_activity_id'; dest='activity_id';
 WHEN 'workshop_time_events' THEN previous='previous_event_id'; dest='time_event_id';
 WHEN 'workshop_part_deliveries' THEN previous='previous_delivery_id'; dest='delivery_id';
 WHEN 'workshop_part_usages' THEN previous='previous_usage_id'; dest='usage_id';
 WHEN 'workshop_external_repairs' THEN previous='previous_record_id'; dest='external_repair_id';
 WHEN 'workshop_cost_records' THEN previous='previous_cost_id'; dest='cost_record_id';
 WHEN 'workshop_material_needs' THEN previous='previous_need_id'; dest='material_need_id';
 ELSE previous='previous_attachment_id'; dest='attachment_id';
 END CASE;
 IF to_jsonb(NEW)->>previous IS NULL THEN RETURN NULL; END IF;
 context=workshop_fact_context(TG_TABLE_NAME,NEW.id);
 needs_approval=TG_TABLE_NAME IN ('workshop_part_deliveries','workshop_part_usages','workshop_external_repairs','workshop_cost_records','workshop_attachments') OR (SELECT status FROM workshop_work_orders WHERE id=(context->>'work_order_id')::uuid) IN ('finished','closed','cancelled');
 IF needs_approval THEN
  SELECT * INTO op FROM workshop_operations o WHERE to_jsonb(o)->>dest=NEW.id::text;
  IF op.id IS NULL OR (SELECT count(*) FROM workshop_authorization_applications a JOIN workshop_authorization_decisions d ON d.id=a.decision_id JOIN workshop_authorization_requests q ON q.id=d.request_id WHERE a.operation_id=op.id AND q.type='rectification' AND q.content->>'fact_table'=TG_TABLE_NAME AND q.content->>'fact_id'=to_jsonb(NEW)->>previous)<>1 THEN RAISE EXCEPTION 'Revisión sensible sin aplicación única de autorización' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NULL;
END; $$;
CREATE FUNCTION workshop_applied_scope_quota() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE requested_scope uuid; target_order uuid;
BEGIN
 SELECT q.scope_id,q.work_order_id INTO requested_scope,target_order FROM workshop_authorization_decisions d JOIN workshop_authorization_requests q ON q.id=d.request_id WHERE d.id=NEW.decision_id;
 IF requested_scope IS NULL THEN RETURN NULL; END IF;
 IF EXISTS(
  SELECT 1 FROM workshop_part_deliveries d JOIN workshop_scope_parts p ON p.id=d.scope_part_id JOIN workshop_scopes s ON s.id=p.scope_id
  WHERE s.work_order_id=target_order AND NOT EXISTS(SELECT 1 FROM workshop_part_deliveries n WHERE n.previous_delivery_id=d.id)
  GROUP BY s.root_record_id,p.line_number
  HAVING sum(d.quantity)>COALESCE((SELECT current_part.quantity FROM workshop_scope_parts current_part JOIN workshop_scopes current_scope ON current_scope.id=current_part.scope_id WHERE current_scope.root_record_id=s.root_record_id AND current_part.line_number=p.line_number AND workshop_effective_scope(current_scope.id)),0)
 ) THEN RAISE EXCEPTION 'Alcance aplicado incompatible con entregas históricas efectivas' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER workshop_applied_scope_quota AFTER INSERT ON workshop_authorization_applications DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_applied_scope_quota();
SQL);
        foreach (['activities', 'time_events', 'part_deliveries', 'part_usages', 'external_repairs', 'cost_records', 'material_needs', 'attachments'] as $table) {
            DB::statement("CREATE CONSTRAINT TRIGGER workshop_sensitive_revision AFTER INSERT ON workshop_{$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_sensitive_fact_audit()");
        }
    }

    public function down(): void
    {
        throw new LogicException('Las rectificaciones históricas requieren recuperación hacia adelante.');
    }
};
