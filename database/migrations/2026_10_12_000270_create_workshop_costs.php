<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE workshop_cost_records (
 id uuid PRIMARY KEY, work_order_id uuid NOT NULL REFERENCES workshop_work_orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT, scope_task_id uuid REFERENCES workshop_scope_tasks(id) ON DELETE RESTRICT ON UPDATE RESTRICT, scope_part_id uuid REFERENCES workshop_scope_parts(id) ON DELETE RESTRICT ON UPDATE RESTRICT, usage_id uuid REFERENCES workshop_part_usages(id) ON DELETE RESTRICT ON UPDATE RESTRICT, external_repair_id uuid,
 cost_kind text CHECK(cost_kind IN ('labor','part','external')), basis text NOT NULL CHECK(basis IN ('estimated','actual')),
 amount numeric(20,6) CHECK(amount>=0 AND amount<100000000000000), currency char(3) CHECK(currency ~ '^[A-Z]{3}$'), source_attachment_id uuid, covered_by_cost_id uuid,
 description text NOT NULL CHECK(btrim(description)<>''), actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT, occurred_at timestamptz(6) NOT NULL, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), reason text NOT NULL CHECK(btrim(reason)<>''),
 root_record_id uuid NOT NULL REFERENCES workshop_cost_records(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, previous_cost_id uuid UNIQUE REFERENCES workshop_cost_records(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED, revision integer NOT NULL CHECK(revision>0), UNIQUE(root_record_id,revision), UNIQUE(id,work_order_id),
 FOREIGN KEY(external_repair_id,work_order_id) REFERENCES workshop_external_repairs(id,work_order_id) ON DELETE RESTRICT ON UPDATE RESTRICT, FOREIGN KEY(covered_by_cost_id,work_order_id) REFERENCES workshop_cost_records(id,work_order_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CHECK(covered_by_cost_id IS DISTINCT FROM id), CHECK(amount IS NULL OR currency IS NOT NULL),
 CHECK((previous_cost_id IS NULL AND root_record_id=id AND revision=1) OR (previous_cost_id IS NOT NULL AND previous_cost_id<>id AND root_record_id<>id AND revision>1)),
 CHECK((cost_kind IS NULL AND source_attachment_id IS NOT NULL AND num_nonnulls(scope_task_id,scope_part_id,usage_id,external_repair_id,covered_by_cost_id)=0)
 OR (cost_kind='labor' AND scope_task_id IS NOT NULL AND num_nonnulls(scope_part_id,usage_id,external_repair_id)=0)
 OR (cost_kind='part' AND num_nonnulls(scope_part_id,usage_id)=1 AND num_nonnulls(scope_task_id,external_repair_id)=0)
 OR (cost_kind='external' AND num_nonnulls(scope_task_id,external_repair_id)=1 AND num_nonnulls(scope_part_id,usage_id)=0))
);
CREATE INDEX ON workshop_cost_records(work_order_id,basis,currency,registered_at,id);
CREATE INDEX ON workshop_cost_records(root_record_id);
CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON workshop_cost_records FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history();
CREATE TRIGGER workshop_no_truncate BEFORE TRUNCATE ON workshop_cost_records EXECUTE FUNCTION workshop_preserve_history();
ALTER TABLE workshop_operations ADD cost_record_id uuid REFERENCES workshop_cost_records(id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED;
CREATE FUNCTION workshop_exact_cost(value text) RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $$ BEGIN
 IF value IS NULL THEN RETURN NULL; END IF;
 IF length(value)>4096 OR value !~ '^[+-]?[0-9]+(\.[0-9]{1,6})?$' THEN RAISE EXCEPTION 'Costo decimal exacto inválido' USING ERRCODE='23514'; END IF;
 IF value::numeric<0 OR value::numeric>=100000000000000 THEN RAISE EXCEPTION 'Costo fuera de rango' USING ERRCODE='23514'; END IF;
 RETURN value::numeric;
END; $$;
CREATE FUNCTION workshop_write_cost(data jsonb, amount_text text) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE exact numeric; item public.workshop_cost_records;
BEGIN
 exact=public.workshop_exact_cost(amount_text);
 item=jsonb_populate_record(NULL::public.workshop_cost_records,data-ARRAY['amount','registered_at']);
 INSERT INTO public.workshop_cost_records(id,work_order_id,scope_task_id,scope_part_id,usage_id,external_repair_id,cost_kind,basis,amount,currency,source_attachment_id,covered_by_cost_id,description,actor_id,occurred_at,reason,root_record_id,previous_cost_id,revision)
 VALUES(item.id,item.work_order_id,item.scope_task_id,item.scope_part_id,item.usage_id,item.external_repair_id,item.cost_kind,item.basis,exact,item.currency,item.source_attachment_id,item.covered_by_cost_id,item.description,item.actor_id,item.occurred_at,item.reason,COALESCE(item.root_record_id,item.id),item.previous_cost_id,COALESCE(item.revision,1));
 RETURN item.id;
END; $$;
REVOKE ALL ON FUNCTION workshop_write_cost(jsonb,text) FROM PUBLIC;
CREATE FUNCTION workshop_cost_record() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent workshop_cost_records; source_order uuid; task workshop_scope_tasks; target uuid;
BEGIN
 PERFORM pg_advisory_xact_lock(873210); PERFORM 1 FROM workshop_work_orders WHERE id=NEW.work_order_id FOR UPDATE;
 PERFORM workshop_require_actor(NEW.actor_id,ARRAY['administrator','secretary']);
 IF NEW.previous_cost_id IS NOT NULL THEN
  SELECT * INTO STRICT parent FROM workshop_cost_records WHERE id=NEW.previous_cost_id;
  IF parent.work_order_id<>NEW.work_order_id OR parent.root_record_id<>NEW.root_record_id OR parent.revision+1<>NEW.revision THEN RAISE EXCEPTION 'Cadena costo incompatible' USING ERRCODE='23514'; END IF;
 END IF;
 IF NEW.scope_task_id IS NOT NULL THEN
  SELECT * INTO STRICT task FROM workshop_scope_tasks WHERE id=NEW.scope_task_id;
  SELECT work_order_id INTO source_order FROM workshop_scopes WHERE id=task.scope_id;
  IF NEW.cost_kind='external' AND task.supplier_id IS NULL THEN RAISE EXCEPTION 'Costo externo sin proveedor autorizado' USING ERRCODE='23514'; END IF;
 ELSIF NEW.scope_part_id IS NOT NULL THEN
  SELECT s.work_order_id INTO source_order FROM workshop_scope_parts p JOIN workshop_scopes s ON s.id=p.scope_id WHERE p.id=NEW.scope_part_id;
 ELSIF NEW.usage_id IS NOT NULL THEN
  SELECT s.work_order_id INTO source_order FROM workshop_part_usages u JOIN workshop_part_deliveries d ON d.id=u.delivery_id JOIN workshop_scope_parts p ON p.id=d.scope_part_id JOIN workshop_scopes s ON s.id=p.scope_id WHERE u.id=NEW.usage_id;
 ELSE source_order=NEW.work_order_id;
 END IF;
 IF source_order IS DISTINCT FROM NEW.work_order_id THEN RAISE EXCEPTION 'Origen de costo de otra orden' USING ERRCODE='23514'; END IF;
 IF NEW.covered_by_cost_id IS NOT NULL THEN
  SELECT * INTO STRICT parent FROM workshop_cost_records WHERE id=NEW.covered_by_cost_id;
  IF parent.basis<>NEW.basis OR parent.currency IS DISTINCT FROM NEW.currency OR parent.cost_kind IS NULL OR EXISTS(SELECT 1 FROM workshop_cost_records n WHERE n.previous_cost_id=parent.id) THEN RAISE EXCEPTION 'Cobertura incompatible/no efectiva' USING ERRCODE='23514'; END IF;
  IF EXISTS(WITH RECURSIVE chain AS (SELECT id,covered_by_cost_id FROM workshop_cost_records WHERE id=NEW.covered_by_cost_id UNION SELECT c.id,c.covered_by_cost_id FROM workshop_cost_records c JOIN chain ON c.id=chain.covered_by_cost_id) SELECT 1 FROM chain WHERE id=NEW.id) THEN RAISE EXCEPTION 'Cobertura cíclica' USING ERRCODE='23514'; END IF;
 END IF;
 -- An indistinguishable communicated origin must be revised rather than counted twice.
 IF NEW.cost_kind IS NOT NULL AND EXISTS(SELECT 1 FROM workshop_cost_records c WHERE c.id IS DISTINCT FROM NEW.previous_cost_id AND c.work_order_id=NEW.work_order_id AND c.basis=NEW.basis AND c.currency IS NOT DISTINCT FROM NEW.currency AND c.cost_kind=NEW.cost_kind AND c.scope_task_id IS NOT DISTINCT FROM NEW.scope_task_id AND c.scope_part_id IS NOT DISTINCT FROM NEW.scope_part_id AND c.usage_id IS NOT DISTINCT FROM NEW.usage_id AND c.external_repair_id IS NOT DISTINCT FROM NEW.external_repair_id AND c.source_attachment_id IS NOT DISTINCT FROM NEW.source_attachment_id AND c.description=NEW.description AND NOT EXISTS(SELECT 1 FROM workshop_cost_records n WHERE n.previous_cost_id=c.id)) THEN RAISE EXCEPTION 'Costo comunicado duplicado requiere revisión' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER workshop_record BEFORE INSERT ON workshop_cost_records FOR EACH ROW EXECUTE FUNCTION workshop_cost_record();
CREATE FUNCTION workshop_material_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE fact jsonb; dest text; actor_field text; actual_order uuid; total integer; op workshop_operations; table_name text;
BEGIN
 IF TG_TABLE_NAME='workshop_operations' THEN
  CASE NEW.operation_type
   WHEN 'delivery_record','delivery_revise' THEN table_name='workshop_part_deliveries'; dest='delivery_id'; actor_field='recorded_by_id';
   WHEN 'usage_record','usage_revise' THEN table_name='workshop_part_usages'; dest='usage_id'; actor_field='confirmed_by_id';
   WHEN 'external_record','external_revise' THEN table_name='workshop_external_repairs'; dest='external_repair_id'; actor_field='recorded_by_id';
   WHEN 'material_need_record','material_need_revise' THEN table_name='workshop_material_needs'; dest='material_need_id'; actor_field='requested_by_id';
   ELSE table_name='workshop_cost_records'; dest='cost_record_id'; actor_field='actor_id';
  END CASE;
  EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id=$1',table_name) INTO fact USING (to_jsonb(NEW)->>dest)::uuid;
  IF fact IS NULL OR NEW.actor_id IS DISTINCT FROM (fact->>actor_field)::bigint OR NEW.after_values IS DISTINCT FROM fact THEN RAISE EXCEPTION 'Recibo incompatible con hecho material/económico' USING ERRCODE='23514'; END IF;
  IF table_name='workshop_part_deliveries' THEN SELECT s.work_order_id INTO actual_order FROM workshop_scope_parts p JOIN workshop_scopes s ON s.id=p.scope_id WHERE p.id=(fact->>'scope_part_id')::uuid;
  ELSIF table_name='workshop_part_usages' THEN SELECT s.work_order_id INTO actual_order FROM workshop_part_deliveries d JOIN workshop_scope_parts p ON p.id=d.scope_part_id JOIN workshop_scopes s ON s.id=p.scope_id WHERE d.id=(fact->>'delivery_id')::uuid;
  ELSE actual_order=(fact->>'work_order_id')::uuid; END IF;
  IF NEW.work_order_id IS DISTINCT FROM actual_order OR num_nonnulls(NEW.report_id,NEW.admission_id,NEW.fleet_asset_id)>0 THEN RAISE EXCEPTION 'Raíz material/económica incompatible' USING ERRCODE='23514'; END IF;
 ELSE
  dest=CASE TG_TABLE_NAME WHEN 'workshop_part_deliveries' THEN 'delivery_id' WHEN 'workshop_part_usages' THEN 'usage_id' WHEN 'workshop_external_repairs' THEN 'external_repair_id' WHEN 'workshop_material_needs' THEN 'material_need_id' ELSE 'cost_record_id' END;
  SELECT count(*) INTO total FROM workshop_operations o WHERE to_jsonb(o)->>dest=NEW.id::text;
  IF total<>1 THEN RAISE EXCEPTION 'Hecho sin recibo único' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER workshop_material_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation_type IN ('delivery_record','delivery_revise','usage_record','usage_revise','external_record','external_revise','material_need_record','material_need_revise','cost_record','cost_revise','cost_classify')) EXECUTE FUNCTION workshop_material_audit();
CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT ON workshop_part_deliveries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_material_audit();
CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT ON workshop_part_usages DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_material_audit();
CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT ON workshop_external_repairs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_material_audit();
CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT ON workshop_material_needs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_material_audit();
CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT ON workshop_cost_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_material_audit();
CREATE FUNCTION workshop_cost_coverage_effective() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM workshop_cost_records c JOIN workshop_cost_records p ON p.id=c.covered_by_cost_id WHERE c.work_order_id=NEW.work_order_id AND NOT EXISTS(SELECT 1 FROM workshop_cost_records n WHERE n.previous_cost_id=c.id) AND (EXISTS(SELECT 1 FROM workshop_cost_records n WHERE n.previous_cost_id=p.id) OR p.cost_kind IS NULL OR p.basis<>c.basis OR p.currency IS DISTINCT FROM c.currency)) THEN RAISE EXCEPTION 'Cobertura dependiente requiere revisión atómica efectiva' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER workshop_coverage_effective AFTER INSERT ON workshop_cost_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_cost_coverage_effective();
CREATE INDEX ON workshop_cost_records(scope_task_id);
CREATE INDEX ON workshop_cost_records(scope_part_id);
CREATE INDEX ON workshop_cost_records(usage_id);
CREATE INDEX ON workshop_cost_records(external_repair_id);
CREATE INDEX ON workshop_cost_records(source_attachment_id);
CREATE INDEX ON workshop_cost_records(covered_by_cost_id);
CREATE INDEX ON workshop_cost_records(actor_id);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Rollback de historial requiere recuperación explícita.');
    }
};
