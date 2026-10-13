<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE workshop_part_deliveries (id uuid PRIMARY KEY, scope_part_id uuid NOT NULL REFERENCES workshop_scope_parts(id) ON DELETE RESTRICT ON UPDATE RESTRICT, quantity numeric(18,6) NOT NULL CHECK(quantity>0 AND quantity<1000000000000), delivered_to_assignment_id uuid NOT NULL REFERENCES workshop_mechanic_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT, recorded_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT, occurred_at timestamptz(6) NOT NULL, reason text NOT NULL CHECK(btrim(reason)<>''), registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), root_record_id uuid NOT NULL REFERENCES workshop_part_deliveries(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, previous_delivery_id uuid UNIQUE REFERENCES workshop_part_deliveries(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, revision integer NOT NULL CHECK(revision>0), UNIQUE(root_record_id,revision), CHECK((previous_delivery_id IS NULL AND root_record_id=id AND revision=1) OR (previous_delivery_id IS NOT NULL AND previous_delivery_id<>id AND root_record_id<>id AND revision>1)));
CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON workshop_part_deliveries FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history();
CREATE TRIGGER workshop_no_truncate BEFORE TRUNCATE ON workshop_part_deliveries EXECUTE FUNCTION workshop_preserve_history();
CREATE INDEX ON workshop_part_deliveries(root_record_id);
CREATE INDEX ON workshop_part_deliveries(scope_part_id);
CREATE INDEX ON workshop_part_deliveries(delivered_to_assignment_id);
CREATE INDEX ON workshop_part_deliveries(recorded_by_id);
CREATE TABLE workshop_part_usages (id uuid PRIMARY KEY, delivery_id uuid NOT NULL REFERENCES workshop_part_deliveries(id) ON DELETE RESTRICT ON UPDATE RESTRICT, quantity numeric(18,6) NOT NULL CHECK(quantity>0 AND quantity<1000000000000), confirmed_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT, mechanic_assignment_id uuid NOT NULL REFERENCES workshop_mechanic_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT, occurred_at timestamptz(6) NOT NULL, reason text NOT NULL CHECK(btrim(reason)<>''), registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), root_record_id uuid NOT NULL REFERENCES workshop_part_usages(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, previous_usage_id uuid UNIQUE REFERENCES workshop_part_usages(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, revision integer NOT NULL CHECK(revision>0), UNIQUE(root_record_id,revision), CHECK((previous_usage_id IS NULL AND root_record_id=id AND revision=1) OR (previous_usage_id IS NOT NULL AND previous_usage_id<>id AND root_record_id<>id AND revision>1)));
CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON workshop_part_usages FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history();
CREATE TRIGGER workshop_no_truncate BEFORE TRUNCATE ON workshop_part_usages EXECUTE FUNCTION workshop_preserve_history();
CREATE INDEX ON workshop_part_usages(root_record_id);
CREATE INDEX ON workshop_part_usages(delivery_id);
CREATE INDEX ON workshop_part_usages(confirmed_by_id);
CREATE INDEX ON workshop_part_usages(mechanic_assignment_id);
CREATE TABLE workshop_external_repairs (id uuid PRIMARY KEY, work_order_id uuid NOT NULL REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT, scope_task_id uuid NOT NULL REFERENCES workshop_scope_tasks(id) ON DELETE RESTRICT ON UPDATE RESTRICT, supplier_id uuid NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT ON UPDATE RESTRICT, description text NOT NULL CHECK(btrim(description)<>''), performed_at timestamptz(6) NOT NULL, recorded_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT, reason text NOT NULL CHECK(btrim(reason)<>''), UNIQUE(id,work_order_id), registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), root_record_id uuid NOT NULL REFERENCES workshop_external_repairs(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, previous_record_id uuid UNIQUE REFERENCES workshop_external_repairs(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, revision integer NOT NULL CHECK(revision>0), UNIQUE(root_record_id,revision), CHECK((previous_record_id IS NULL AND root_record_id=id AND revision=1) OR (previous_record_id IS NOT NULL AND previous_record_id<>id AND root_record_id<>id AND revision>1)));
CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON workshop_external_repairs FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history();
CREATE TRIGGER workshop_no_truncate BEFORE TRUNCATE ON workshop_external_repairs EXECUTE FUNCTION workshop_preserve_history();
CREATE INDEX ON workshop_external_repairs(root_record_id);
CREATE INDEX ON workshop_external_repairs(work_order_id);
CREATE INDEX ON workshop_external_repairs(scope_task_id);
CREATE INDEX ON workshop_external_repairs(supplier_id);
CREATE INDEX ON workshop_external_repairs(recorded_by_id);
CREATE TABLE workshop_material_needs (id uuid PRIMARY KEY, work_order_id uuid NOT NULL REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT, scope_part_id uuid REFERENCES workshop_scope_parts(id) ON DELETE RESTRICT ON UPDATE RESTRICT, spare_part_id uuid NOT NULL REFERENCES spare_parts(id) ON DELETE RESTRICT ON UPDATE RESTRICT, quantity numeric(18,6) NOT NULL CHECK(quantity>0 AND quantity<1000000000000), description text NOT NULL CHECK(btrim(description)<>''), requested_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT, occurred_at timestamptz(6) NOT NULL, reason text NOT NULL CHECK(btrim(reason)<>''), UNIQUE(id,work_order_id), registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), root_record_id uuid NOT NULL REFERENCES workshop_material_needs(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, previous_need_id uuid UNIQUE REFERENCES workshop_material_needs(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, revision integer NOT NULL CHECK(revision>0), UNIQUE(root_record_id,revision), CHECK((previous_need_id IS NULL AND root_record_id=id AND revision=1) OR (previous_need_id IS NOT NULL AND previous_need_id<>id AND root_record_id<>id AND revision>1)));
CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON workshop_material_needs FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history();
CREATE TRIGGER workshop_no_truncate BEFORE TRUNCATE ON workshop_material_needs EXECUTE FUNCTION workshop_preserve_history();
CREATE INDEX ON workshop_material_needs(root_record_id);
CREATE INDEX ON workshop_material_needs(work_order_id);
CREATE INDEX ON workshop_material_needs(scope_part_id);
CREATE INDEX ON workshop_material_needs(spare_part_id);
CREATE INDEX ON workshop_material_needs(requested_by_id);
ALTER TABLE workshop_operations ADD delivery_id uuid REFERENCES workshop_part_deliveries(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE workshop_operations ADD usage_id uuid REFERENCES workshop_part_usages(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE workshop_operations ADD external_repair_id uuid REFERENCES workshop_external_repairs(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE workshop_operations ADD material_need_id uuid REFERENCES workshop_material_needs(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
CREATE FUNCTION workshop_effective_scope(scope_target uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM workshop_scopes s JOIN workshop_authorization_requests q ON q.scope_id=s.id JOIN workshop_authorization_decisions d ON d.request_id=q.id JOIN workshop_authorization_applications a ON a.decision_id=d.id WHERE s.id=scope_target AND d.decision='approved' AND NOT EXISTS(SELECT 1 FROM workshop_scopes successor JOIN workshop_authorization_requests sq ON sq.scope_id=successor.id JOIN workshop_authorization_decisions sd ON sd.request_id=sq.id JOIN workshop_authorization_applications sa ON sa.decision_id=sd.id WHERE successor.root_record_id=s.root_record_id AND successor.revision>s.revision));
$$;
CREATE FUNCTION workshop_material_record() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE part workshop_scope_parts; scope workshop_scopes; delivery workshop_part_deliveries; assignment workshop_mechanic_assignments; task workshop_scope_tasks; prior jsonb; prev uuid; actor bigint; target uuid; max_quantity numeric; used numeric;
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 IF TG_TABLE_NAME='workshop_part_deliveries' THEN
  SELECT * INTO STRICT part FROM workshop_scope_parts WHERE id=NEW.scope_part_id;
  SELECT * INTO STRICT scope FROM workshop_scopes WHERE id=part.scope_id;
  SELECT * INTO STRICT assignment FROM workshop_mechanic_assignments WHERE id=NEW.delivered_to_assignment_id;
  target=scope.work_order_id; actor=NEW.recorded_by_id; prev=NEW.previous_delivery_id;
  PERFORM workshop_require_actor(actor,ARRAY['administrator','secretary']);
 ELSIF TG_TABLE_NAME='workshop_part_usages' THEN
  SELECT * INTO STRICT delivery FROM workshop_part_deliveries WHERE id=NEW.delivery_id;
  SELECT * INTO STRICT part FROM workshop_scope_parts WHERE id=delivery.scope_part_id;
  SELECT * INTO STRICT scope FROM workshop_scopes WHERE id=part.scope_id;
  SELECT * INTO STRICT assignment FROM workshop_mechanic_assignments WHERE id=NEW.mechanic_assignment_id;
  target=scope.work_order_id; actor=NEW.confirmed_by_id; prev=NEW.previous_usage_id;
  PERFORM workshop_require_actor(actor,ARRAY['administrator','mechanic']);
  IF NEW.occurred_at<delivery.occurred_at OR NEW.mechanic_assignment_id<>delivery.delivered_to_assignment_id THEN RAISE EXCEPTION 'Uso incompatible con entrega' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='workshop_external_repairs' THEN
  target=NEW.work_order_id; actor=NEW.recorded_by_id; prev=NEW.previous_record_id;
  PERFORM workshop_require_actor(actor,ARRAY['administrator']);
  SELECT * INTO STRICT task FROM workshop_scope_tasks WHERE id=NEW.scope_task_id;
  SELECT * INTO STRICT scope FROM workshop_scopes WHERE id=task.scope_id;
  IF scope.work_order_id<>target OR task.supplier_id IS DISTINCT FROM NEW.supplier_id OR NOT workshop_effective_scope(scope.id) THEN RAISE EXCEPTION 'Proveedor o tarea externa no autorizados' USING ERRCODE='23514'; END IF;
 ELSE
  target=NEW.work_order_id; actor=NEW.requested_by_id; prev=NEW.previous_need_id;
  PERFORM workshop_require_actor(actor,ARRAY['administrator','secretary','mechanic']);
  IF NEW.scope_part_id IS NOT NULL THEN
   SELECT * INTO STRICT part FROM workshop_scope_parts WHERE id=NEW.scope_part_id;
   SELECT * INTO STRICT scope FROM workshop_scopes WHERE id=part.scope_id;
   IF scope.work_order_id<>target OR part.spare_part_id<>NEW.spare_part_id THEN RAISE EXCEPTION 'Necesidad de otra orden/repuesto' USING ERRCODE='23514'; END IF;
  END IF;
 END IF;
 PERFORM 1 FROM workshop_work_orders WHERE id=target FOR UPDATE;
 IF prev IS NULL AND (SELECT status FROM workshop_work_orders WHERE id=target) IN ('finished','closed','cancelled') THEN RAISE EXCEPTION 'Orden terminal' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=actor AND r.name IN ('administrator','secretary')) AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.work_order_id=target AND e.user_id=actor AND e.is_active AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_releases r WHERE r.assignment_id=a.id)) THEN RAISE EXCEPTION 'Participación no vigente' USING ERRCODE='23514'; END IF;
 IF assignment.id IS NOT NULL AND (assignment.work_order_id<>target OR (to_jsonb(NEW)->>'occurred_at')::timestamptz<assignment.starts_at OR EXISTS(SELECT 1 FROM workshop_mechanic_releases r WHERE r.assignment_id=assignment.id) OR NOT EXISTS(SELECT 1 FROM employees e JOIN users u ON u.id=e.user_id JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE e.id=assignment.employee_id AND e.is_active AND u.is_active AND r.name='mechanic')) THEN RAISE EXCEPTION 'Asignación no vigente' USING ERRCODE='23514'; END IF;
 IF prev IS NOT NULL THEN
  EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id=$1',TG_TABLE_NAME) INTO prior USING prev;
  IF prior IS NULL OR (prior->>'root_record_id')::uuid<>NEW.root_record_id OR (prior->>'revision')::integer+1<>NEW.revision THEN RAISE EXCEPTION 'Revisión incompatible' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='workshop_part_deliveries' AND prior->>'scope_part_id' IS DISTINCT FROM to_jsonb(NEW)->>'scope_part_id' THEN RAISE EXCEPTION 'Origen histórico incompatible' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='workshop_part_usages' AND prior->>'delivery_id' IS DISTINCT FROM to_jsonb(NEW)->>'delivery_id' THEN RAISE EXCEPTION 'Entrega histórica incompatible' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME IN ('workshop_material_needs','workshop_external_repairs') AND prior->>'work_order_id' IS DISTINCT FROM target::text THEN RAISE EXCEPTION 'Orden histórica incompatible' USING ERRCODE='23514'; END IF;

 END IF;
 IF TG_TABLE_NAME IN ('workshop_part_deliveries','workshop_part_usages') THEN
  SELECT p.quantity INTO max_quantity FROM workshop_scope_parts p JOIN workshop_scopes s ON s.id=p.scope_id WHERE s.root_record_id=scope.root_record_id AND p.line_number=part.line_number AND workshop_effective_scope(s.id);
  IF max_quantity IS NULL THEN RAISE EXCEPTION 'Línea sin autorización vigente' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='workshop_part_deliveries' THEN
   SELECT COALESCE(sum(d.quantity),0) INTO used FROM workshop_part_deliveries d JOIN workshop_scope_parts p ON p.id=d.scope_part_id JOIN workshop_scopes s ON s.id=p.scope_id WHERE s.root_record_id=scope.root_record_id AND p.line_number=part.line_number AND d.id IS DISTINCT FROM NEW.previous_delivery_id AND NOT EXISTS(SELECT 1 FROM workshop_part_deliveries n WHERE n.previous_delivery_id=d.id);
   IF used+NEW.quantity>max_quantity THEN RAISE EXCEPTION 'Entrega excede cantidad autorizada' USING ERRCODE='23514'; END IF;
   SELECT COALESCE(sum(u.quantity),0) INTO used FROM workshop_part_usages u JOIN workshop_part_deliveries d ON d.id=u.delivery_id WHERE d.root_record_id=NEW.root_record_id AND NOT EXISTS(SELECT 1 FROM workshop_part_usages n WHERE n.previous_usage_id=u.id);
   IF used>NEW.quantity THEN RAISE EXCEPTION 'Entrega corregida inferior a uso comunicado' USING ERRCODE='23514'; END IF;
  ELSE
   SELECT COALESCE(sum(u.quantity),0) INTO used FROM workshop_part_usages u JOIN workshop_part_deliveries d ON d.id=u.delivery_id WHERE d.root_record_id=delivery.root_record_id AND u.id IS DISTINCT FROM NEW.previous_usage_id AND NOT EXISTS(SELECT 1 FROM workshop_part_usages n WHERE n.previous_usage_id=u.id);
   SELECT quantity INTO max_quantity FROM workshop_part_deliveries d WHERE d.root_record_id=delivery.root_record_id AND NOT EXISTS(SELECT 1 FROM workshop_part_deliveries n WHERE n.previous_delivery_id=d.id);
   IF used+NEW.quantity>max_quantity THEN RAISE EXCEPTION 'Uso excede entrega efectiva' USING ERRCODE='23514'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER workshop_record BEFORE INSERT ON workshop_part_deliveries FOR EACH ROW EXECUTE FUNCTION workshop_material_record();
CREATE TRIGGER workshop_record BEFORE INSERT ON workshop_part_usages FOR EACH ROW EXECUTE FUNCTION workshop_material_record();
CREATE TRIGGER workshop_record BEFORE INSERT ON workshop_external_repairs FOR EACH ROW EXECUTE FUNCTION workshop_material_record();
CREATE TRIGGER workshop_record BEFORE INSERT ON workshop_material_needs FOR EACH ROW EXECUTE FUNCTION workshop_material_record();
CREATE FUNCTION workshop_write_delivery(data jsonb, quantity_text text) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE exact numeric; item public.workshop_part_deliveries;
BEGIN
 exact=public.workshop_exact_quantity(quantity_text);
 item=jsonb_populate_record(NULL::public.workshop_part_deliveries,data-ARRAY['quantity','registered_at']);
 INSERT INTO public.workshop_part_deliveries(id,scope_part_id,delivered_to_assignment_id,recorded_by_id,occurred_at,reason,quantity,root_record_id,previous_delivery_id,revision) VALUES(item.id,item.scope_part_id,item.delivered_to_assignment_id,item.recorded_by_id,item.occurred_at,item.reason,exact,COALESCE(item.root_record_id,item.id),item.previous_delivery_id,COALESCE(item.revision,1));
 RETURN item.id;
END; $$;
REVOKE ALL ON FUNCTION workshop_write_delivery(jsonb,text) FROM PUBLIC;
CREATE FUNCTION workshop_write_usage(data jsonb, quantity_text text) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE exact numeric; item public.workshop_part_usages;
BEGIN
 exact=public.workshop_exact_quantity(quantity_text);
 item=jsonb_populate_record(NULL::public.workshop_part_usages,data-ARRAY['quantity','registered_at']);
 INSERT INTO public.workshop_part_usages(id,delivery_id,mechanic_assignment_id,confirmed_by_id,occurred_at,reason,quantity,root_record_id,previous_usage_id,revision) VALUES(item.id,item.delivery_id,item.mechanic_assignment_id,item.confirmed_by_id,item.occurred_at,item.reason,exact,COALESCE(item.root_record_id,item.id),item.previous_usage_id,COALESCE(item.revision,1));
 RETURN item.id;
END; $$;
REVOKE ALL ON FUNCTION workshop_write_usage(jsonb,text) FROM PUBLIC;
CREATE FUNCTION workshop_write_material_need(data jsonb, quantity_text text) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE exact numeric; item public.workshop_material_needs;
BEGIN
 exact=public.workshop_exact_quantity(quantity_text);
 item=jsonb_populate_record(NULL::public.workshop_material_needs,data-ARRAY['quantity','registered_at']);
 INSERT INTO public.workshop_material_needs(id,work_order_id,scope_part_id,spare_part_id,description,requested_by_id,occurred_at,reason,quantity,root_record_id,previous_need_id,revision) VALUES(item.id,item.work_order_id,item.scope_part_id,item.spare_part_id,item.description,item.requested_by_id,item.occurred_at,item.reason,exact,COALESCE(item.root_record_id,item.id),item.previous_need_id,COALESCE(item.revision,1));
 RETURN item.id;
END; $$;
REVOKE ALL ON FUNCTION workshop_write_material_need(jsonb,text) FROM PUBLIC;
DO $$ DECLARE constraint_sql text; BEGIN
 SELECT pg_get_constraintdef(oid) INTO constraint_sql FROM pg_constraint WHERE conrelid='workshop_operations'::regclass AND conname='workshop_operations_operation_type_check';
 ALTER TABLE workshop_operations DROP CONSTRAINT workshop_operations_operation_type_check;
 EXECUTE 'ALTER TABLE workshop_operations ADD CONSTRAINT workshop_operations_operation_type_check CHECK ((' || substring(constraint_sql from 8 for length(constraint_sql)-8) || ') OR operation_type IN (''delivery_record'',''delivery_revise'',''usage_record'',''usage_revise'',''external_record'',''external_revise'',''material_need_record'',''material_need_revise'',''cost_record'',''cost_revise'',''cost_classify'',''attachment_reserve'',''attachment_confirm'',''attachment_release''))';
END $$;
DO $$ DECLARE t record; definition text; marker text='NEW.operation_type NOT IN (''delivery_record'',''delivery_revise'',''usage_record'',''usage_revise'',''external_record'',''external_revise'',''material_need_record'',''material_need_revise'',''cost_record'',''cost_revise'',''cost_classify'',''attachment_reserve'',''attachment_confirm'',''attachment_release'')'; BEGIN
 FOR t IN SELECT tgname,pg_get_triggerdef(oid) AS definition FROM pg_trigger WHERE tgrelid='workshop_operations'::regclass AND NOT tgisinternal AND (tgtype & 1)=1 AND (tgtype & 4)=4 AND (tgtype & 24)=0 LOOP
  definition=t.definition;
  IF position(' WHEN (' in definition)>0 THEN definition=replace(definition,' WHEN (',' WHEN ('||marker||' AND ('); definition=replace(definition,') EXECUTE FUNCTION',')) EXECUTE FUNCTION');
  ELSE definition=replace(definition,' EXECUTE FUNCTION',' WHEN ('||marker||') EXECUTE FUNCTION'); END IF;
  EXECUTE format('DROP TRIGGER %I ON workshop_operations',t.tgname); EXECUTE definition;
 END LOOP;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Rollback de historial de Taller requiere recuperación explícita.');
    }
};
