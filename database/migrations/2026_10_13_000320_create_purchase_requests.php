<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE purchase_requests (
 id uuid PRIMARY KEY, supplier_id uuid NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 reason text NOT NULL CHECK(length(btrim(reason))>0), requested_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 registered_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE purchase_request_items (
 id uuid PRIMARY KEY, purchase_request_id uuid NOT NULL REFERENCES purchase_requests(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 spare_part_id uuid NOT NULL REFERENCES spare_parts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 workshop_material_need_id uuid REFERENCES workshop_material_needs(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 code_snapshot text NOT NULL, description_snapshot text NOT NULL, unit_snapshot text NOT NULL,
 quantity numeric NOT NULL CHECK(quantity>0 AND quantity<=999999999999.999999 AND scale(quantity)<=6)
);
CREATE TABLE purchase_request_decisions (
 id uuid PRIMARY KEY, purchase_request_id uuid NOT NULL UNIQUE REFERENCES purchase_requests(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 decision text NOT NULL CHECK(decision IN ('approved','rejected')), reason text NOT NULL CHECK(length(btrim(reason))>0),
 decided_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 registered_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE purchasing_operations (
 id uuid PRIMARY KEY, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 scope text NOT NULL CHECK(scope IN ('request_create','request_decide')), request_key uuid NOT NULL, fingerprint text NOT NULL CHECK(fingerprint~'^[a-f0-9]{64}$'),
 purchase_request_id uuid NOT NULL REFERENCES purchase_requests(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 decision_id uuid UNIQUE REFERENCES purchase_request_decisions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 reason text NOT NULL CHECK(length(btrim(reason))>0), confirmed_result jsonb NOT NULL CHECK(jsonb_typeof(confirmed_result)='object'),
 registered_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(actor_id,scope,request_key),
 CHECK((scope='request_create' AND decision_id IS NULL) OR (scope='request_decide' AND decision_id IS NOT NULL))
);
CREATE UNIQUE INDEX purchasing_request_creation ON purchasing_operations(purchase_request_id) WHERE scope='request_create';
CREATE INDEX purchase_requests_supplier ON purchase_requests(supplier_id);
CREATE INDEX purchase_requests_actor ON purchase_requests(requested_by_id);
CREATE INDEX purchase_request_items_request ON purchase_request_items(purchase_request_id);
CREATE INDEX purchase_request_items_part ON purchase_request_items(spare_part_id);
CREATE INDEX purchase_request_items_need ON purchase_request_items(workshop_material_need_id);
CREATE FUNCTION purchasing_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Registro de compras inmutable' USING ERRCODE='23514'; END;
$$;
CREATE FUNCTION purchasing_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor bigint; source spare_parts; need_part uuid;
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 IF TG_TABLE_NAME='purchase_requests' THEN actor=NEW.requested_by_id;
 ELSIF TG_TABLE_NAME='purchase_request_decisions' THEN actor=NEW.decided_by_id;
 ELSIF TG_TABLE_NAME='purchasing_operations' THEN actor=NEW.actor_id;
 ELSE
  IF EXISTS(SELECT 1 FROM purchasing_operations WHERE purchase_request_id=NEW.purchase_request_id AND scope='request_create') OR EXISTS(SELECT 1 FROM purchase_request_decisions WHERE purchase_request_id=NEW.purchase_request_id) THEN RAISE EXCEPTION 'Alcance ya confirmado' USING ERRCODE='23514'; END IF;
  SELECT * INTO STRICT source FROM spare_parts WHERE id=NEW.spare_part_id;
  IF (NEW.code_snapshot,NEW.description_snapshot,NEW.unit_snapshot) IS DISTINCT FROM (source.code,source.description,source.unit) THEN RAISE EXCEPTION 'Repuesto histórico incompatible' USING ERRCODE='23514'; END IF;
  IF NEW.workshop_material_need_id IS NOT NULL THEN
   SELECT COALESCE(n.spare_part_id,p.spare_part_id) INTO need_part FROM workshop_material_needs n LEFT JOIN workshop_scope_parts p ON p.id=n.scope_part_id WHERE n.id=NEW.workshop_material_need_id;
   IF need_part IS DISTINCT FROM NEW.spare_part_id THEN RAISE EXCEPTION 'Necesidad de otro repuesto' USING ERRCODE='23514'; END IF;
  END IF;
  RETURN NEW;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=actor AND u.is_active AND r.name='administrator') THEN RAISE EXCEPTION 'Administrador activo requerido' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END;
$$;
CREATE FUNCTION purchasing_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE request_id uuid; actor bigint; target_decision purchase_request_decisions;
BEGIN
 IF TG_TABLE_NAME='purchase_requests' THEN
  request_id=NEW.id;
  IF NOT EXISTS(SELECT 1 FROM purchase_request_items WHERE purchase_request_id=request_id) THEN RAISE EXCEPTION 'Solicitud sin líneas' USING ERRCODE='23514'; END IF;
  IF NOT EXISTS(SELECT 1 FROM purchasing_operations WHERE scope='request_create' AND purchase_request_id=request_id AND actor_id=NEW.requested_by_id) THEN RAISE EXCEPTION 'Solicitud sin operación auditada' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='purchase_request_decisions' THEN
  IF NOT EXISTS(SELECT 1 FROM purchasing_operations WHERE scope='request_decide' AND decision_id=NEW.id AND purchase_request_id=NEW.purchase_request_id AND actor_id=NEW.decided_by_id) THEN RAISE EXCEPTION 'Decisión sin operación auditada' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.confirmed_result IS DISTINCT FROM jsonb_build_object('purchase_request_id',NEW.purchase_request_id,'decision_id',NEW.decision_id) THEN RAISE EXCEPTION 'Resultado confirmado incompatible' USING ERRCODE='23514'; END IF;
  IF NEW.scope='request_create' AND NOT EXISTS(SELECT 1 FROM purchase_requests WHERE id=NEW.purchase_request_id AND requested_by_id=NEW.actor_id AND reason=NEW.reason) THEN RAISE EXCEPTION 'Actor de solicitud incompatible' USING ERRCODE='23514'; END IF;
  IF NEW.scope='request_decide' AND NOT EXISTS(SELECT 1 FROM purchase_request_decisions WHERE id=NEW.decision_id AND purchase_request_id=NEW.purchase_request_id AND decided_by_id=NEW.actor_id AND reason=NEW.reason) THEN RAISE EXCEPTION 'Destino de decisión incompatible' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NULL;
END;
$$;
CREATE TRIGGER purchasing_validate BEFORE INSERT ON purchase_requests FOR EACH ROW EXECUTE FUNCTION purchasing_validate();
CREATE TRIGGER purchasing_validate BEFORE INSERT ON purchase_request_items FOR EACH ROW EXECUTE FUNCTION purchasing_validate();
CREATE TRIGGER purchasing_validate BEFORE INSERT ON purchase_request_decisions FOR EACH ROW EXECUTE FUNCTION purchasing_validate();
CREATE TRIGGER purchasing_validate BEFORE INSERT ON purchasing_operations FOR EACH ROW EXECUTE FUNCTION purchasing_validate();
CREATE CONSTRAINT TRIGGER purchasing_complete AFTER INSERT ON purchase_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION purchasing_complete();
CREATE CONSTRAINT TRIGGER purchasing_complete AFTER INSERT ON purchase_request_decisions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION purchasing_complete();
CREATE CONSTRAINT TRIGGER purchasing_complete AFTER INSERT ON purchasing_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION purchasing_complete();
CREATE TRIGGER purchasing_immutable BEFORE UPDATE OR DELETE ON purchase_requests FOR EACH ROW EXECUTE FUNCTION purchasing_immutable();
CREATE TRIGGER purchasing_immutable BEFORE UPDATE OR DELETE ON purchase_request_items FOR EACH ROW EXECUTE FUNCTION purchasing_immutable();
CREATE TRIGGER purchasing_immutable BEFORE UPDATE OR DELETE ON purchase_request_decisions FOR EACH ROW EXECUTE FUNCTION purchasing_immutable();
CREATE TRIGGER purchasing_immutable BEFORE UPDATE OR DELETE ON purchasing_operations FOR EACH ROW EXECUTE FUNCTION purchasing_immutable();
CREATE TRIGGER purchasing_no_truncate BEFORE TRUNCATE ON purchase_requests FOR EACH STATEMENT EXECUTE FUNCTION purchasing_immutable();
CREATE TRIGGER purchasing_no_truncate BEFORE TRUNCATE ON purchase_request_items FOR EACH STATEMENT EXECUTE FUNCTION purchasing_immutable();
CREATE TRIGGER purchasing_no_truncate BEFORE TRUNCATE ON purchase_request_decisions FOR EACH STATEMENT EXECUTE FUNCTION purchasing_immutable();
CREATE TRIGGER purchasing_no_truncate BEFORE TRUNCATE ON purchasing_operations FOR EACH STATEMENT EXECUTE FUNCTION purchasing_immutable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE purchasing_operations, purchase_request_decisions, purchase_request_items, purchase_requests; DROP FUNCTION purchasing_complete(), purchasing_validate(), purchasing_immutable();');
    }
};
