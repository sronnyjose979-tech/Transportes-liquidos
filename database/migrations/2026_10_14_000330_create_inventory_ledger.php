<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE inventory_operations(id uuid PRIMARY KEY, actor_id bigint NOT NULL REFERENCES users(id), request_key uuid NOT NULL, scope text NOT NULL, fingerprint text NOT NULL, result jsonb NOT NULL, registered_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(actor_id,scope,request_key));
CREATE TABLE inventory_corrections(id uuid PRIMARY KEY, spare_part_id uuid NOT NULL REFERENCES spare_parts(id), kind text NOT NULL CHECK(kind IN ('initial','adjustment','reversal')), quantity numeric NOT NULL CHECK(scale(quantity)<=6 AND abs(quantity)<1000000000000), unit_snapshot text NOT NULL, reverses_movement_id uuid, requested_by_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL CHECK(btrim(reason)<>''), occurred_at timestamptz NOT NULL, registered_at timestamptz NOT NULL DEFAULT clock_timestamp(), CHECK((kind='initial' AND quantity>=0 AND reverses_movement_id IS NULL) OR (kind='adjustment' AND quantity<>0 AND reverses_movement_id IS NULL) OR (kind='reversal' AND reverses_movement_id IS NOT NULL)));
CREATE TABLE inventory_decisions(id uuid PRIMARY KEY, correction_id uuid NOT NULL UNIQUE REFERENCES inventory_corrections(id), decision text NOT NULL CHECK(decision IN ('approved','rejected')), decided_by_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL CHECK(btrim(reason)<>''), occurred_at timestamptz NOT NULL, registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE inventory_applications(id uuid PRIMARY KEY, decision_id uuid NOT NULL UNIQUE REFERENCES inventory_decisions(id), applied_by_id bigint NOT NULL REFERENCES users(id), occurred_at timestamptz NOT NULL, registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE inventory_returns(id uuid PRIMARY KEY, delivery_id uuid NOT NULL REFERENCES workshop_part_deliveries(id), quantity numeric NOT NULL CHECK(scale(quantity)<=6 AND quantity>0 AND quantity<1000000000000), received_by_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL CHECK(btrim(reason)<>''), occurred_at timestamptz NOT NULL, registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE inventory_movements(id uuid PRIMARY KEY, spare_part_id uuid NOT NULL REFERENCES spare_parts(id), unit_snapshot text NOT NULL, kind text NOT NULL CHECK(kind IN ('initial','adjustment','reversal','receipt','delivery','usage','return')), warehouse_delta numeric NOT NULL CHECK(scale(warehouse_delta)<=6 AND abs(warehouse_delta)<1000000000000), custody_delta numeric NOT NULL CHECK(scale(custody_delta)<=6 AND abs(custody_delta)<1000000000000), consumed_delta numeric NOT NULL CHECK(scale(consumed_delta)<=6 AND abs(consumed_delta)<1000000000000), delivery_id uuid REFERENCES workshop_part_deliveries(id), delivery_record_id uuid UNIQUE REFERENCES workshop_part_deliveries(id), usage_id uuid UNIQUE REFERENCES workshop_part_usages(id), return_id uuid UNIQUE REFERENCES inventory_returns(id), application_id uuid UNIQUE REFERENCES inventory_applications(id), purchase_receipt_item_id uuid UNIQUE, reverses_movement_id uuid UNIQUE REFERENCES inventory_movements(id), actor_id bigint NOT NULL REFERENCES users(id), occurred_at timestamptz NOT NULL, registered_at timestamptz NOT NULL DEFAULT clock_timestamp(), CHECK((kind IN ('initial','adjustment','reversal') AND application_id IS NOT NULL AND num_nonnulls(usage_id,return_id,purchase_receipt_item_id,delivery_id,delivery_record_id)=0) OR (kind='receipt' AND purchase_receipt_item_id IS NOT NULL AND num_nonnulls(usage_id,return_id,application_id,delivery_id,delivery_record_id,reverses_movement_id)=0) OR (kind='delivery' AND delivery_id IS NOT NULL AND delivery_record_id IS NOT NULL AND num_nonnulls(usage_id,return_id,application_id,purchase_receipt_item_id,reverses_movement_id)=0) OR (kind='usage' AND delivery_id IS NOT NULL AND usage_id IS NOT NULL AND num_nonnulls(delivery_record_id,return_id,application_id,purchase_receipt_item_id,reverses_movement_id)=0) OR (kind='return' AND delivery_id IS NOT NULL AND return_id IS NOT NULL AND num_nonnulls(delivery_record_id,usage_id,application_id,purchase_receipt_item_id,reverses_movement_id)=0)));
ALTER TABLE inventory_corrections ADD FOREIGN KEY(reverses_movement_id) REFERENCES inventory_movements(id);
CREATE UNIQUE INDEX inventory_initial_part ON inventory_movements(spare_part_id) WHERE kind='initial';
CREATE UNIQUE INDEX inventory_delivery ON inventory_movements(delivery_id) WHERE kind='delivery' AND delivery_record_id=delivery_id;
CREATE INDEX ON inventory_movements(spare_part_id);
CREATE INDEX ON inventory_movements(delivery_id);
CREATE FUNCTION inventory_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE correction inventory_corrections; decision inventory_decisions; delivery workshop_part_deliveries; usage workshop_part_usages; ret inventory_returns; original inventory_movements; part workshop_scope_parts; initialized boolean; total numeric; actor bigint;
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 UPDATE transport_assignment_guard SET version=version+1 WHERE id=1;
 IF TG_TABLE_NAME='inventory_corrections' THEN
  PERFORM workshop_require_actor(NEW.requested_by_id,ARRAY['administrator','secretary']);
  IF NEW.unit_snapshot IS DISTINCT FROM (SELECT unit FROM spare_parts WHERE id=NEW.spare_part_id) THEN RAISE EXCEPTION 'Unidad incompatible' USING ERRCODE='23514'; END IF;
  IF NEW.kind='reversal' THEN
   SELECT * INTO STRICT original FROM inventory_movements WHERE id=NEW.reverses_movement_id;
   IF original.kind NOT IN ('adjustment','reversal') OR original.spare_part_id<>NEW.spare_part_id OR NEW.quantity<>-original.warehouse_delta OR NEW.occurred_at<original.occurred_at THEN RAISE EXCEPTION 'Reversión incompatible' USING ERRCODE='23514'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='inventory_decisions' THEN
  PERFORM workshop_require_actor(NEW.decided_by_id,ARRAY['administrator']);
  SELECT * INTO STRICT correction FROM inventory_corrections WHERE id=NEW.correction_id;
  IF NEW.occurred_at<correction.occurred_at THEN RAISE EXCEPTION 'Fecha anterior a solicitud' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='inventory_applications' THEN
  PERFORM workshop_require_actor(NEW.applied_by_id,ARRAY['administrator']);
  SELECT * INTO STRICT decision FROM inventory_decisions WHERE id=NEW.decision_id;
  IF decision.decision<>'approved' OR NEW.occurred_at<decision.occurred_at THEN RAISE EXCEPTION 'Decisión no aprobada o fecha incompatible' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='inventory_returns' THEN
  PERFORM workshop_require_actor(NEW.received_by_id,ARRAY['administrator','secretary']);
  SELECT * INTO STRICT delivery FROM workshop_part_deliveries WHERE id=NEW.delivery_id;
  IF NEW.occurred_at<delivery.occurred_at OR NOT EXISTS(SELECT 1 FROM inventory_movements WHERE delivery_id=delivery.id AND kind='delivery') THEN RAISE EXCEPTION 'Entrega no conciliada o fecha incompatible' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT EXISTS(SELECT 1 FROM inventory_movements WHERE spare_part_id=NEW.spare_part_id AND kind='initial') INTO initialized;
  IF NEW.unit_snapshot IS DISTINCT FROM (SELECT unit FROM spare_parts WHERE id=NEW.spare_part_id) OR (NEW.kind<>'initial' AND NOT initialized) THEN RAISE EXCEPTION 'Conciliación inicial/unidad requerida' USING ERRCODE='23514'; END IF;
  IF NEW.kind IN ('initial','adjustment','reversal') THEN
   SELECT c.* INTO STRICT correction FROM inventory_applications a JOIN inventory_decisions d ON d.id=a.decision_id JOIN inventory_corrections c ON c.id=d.correction_id WHERE a.id=NEW.application_id AND d.decision='approved';
   IF NEW.kind<>correction.kind OR NEW.spare_part_id<>correction.spare_part_id OR NEW.warehouse_delta<>correction.quantity OR NEW.custody_delta<>0 OR NEW.consumed_delta<>0 OR NEW.reverses_movement_id IS DISTINCT FROM correction.reverses_movement_id OR NEW.delivery_id IS NOT NULL THEN RAISE EXCEPTION 'Movimiento no autorizado' USING ERRCODE='23514'; END IF;
   IF NEW.actor_id<>(SELECT applied_by_id FROM inventory_applications WHERE id=NEW.application_id) OR NEW.occurred_at<>(SELECT occurred_at FROM inventory_applications WHERE id=NEW.application_id) THEN RAISE EXCEPTION 'Actor/fecha incompatible' USING ERRCODE='23514'; END IF;
  ELSIF NEW.kind IN ('delivery','usage','return') THEN
   SELECT * INTO STRICT delivery FROM workshop_part_deliveries WHERE id=COALESCE(NEW.delivery_record_id,NEW.delivery_id);
   SELECT * INTO STRICT part FROM workshop_scope_parts WHERE id=delivery.scope_part_id;
   IF NEW.spare_part_id<>part.spare_part_id OR NEW.unit_snapshot<>part.unit_snapshot THEN RAISE EXCEPTION 'Repuesto incompatible' USING ERRCODE='23514'; END IF;
   IF NEW.kind='delivery' THEN
    IF NEW.delivery_record_id IS NULL OR NEW.delivery_id<>delivery.root_record_id OR NEW.warehouse_delta<>-(delivery.quantity-COALESCE((SELECT quantity FROM workshop_part_deliveries WHERE id=delivery.previous_delivery_id),0)) OR NEW.custody_delta<>(delivery.quantity-COALESCE((SELECT quantity FROM workshop_part_deliveries WHERE id=delivery.previous_delivery_id),0)) OR NEW.consumed_delta<>0 OR NEW.actor_id<>delivery.recorded_by_id OR NEW.occurred_at<>delivery.occurred_at THEN RAISE EXCEPTION 'Entrega incompatible' USING ERRCODE='23514'; END IF;
   ELSIF NEW.kind='usage' THEN
    SELECT * INTO STRICT usage FROM workshop_part_usages WHERE id=NEW.usage_id;
    IF (SELECT root_record_id FROM workshop_part_deliveries WHERE id=usage.delivery_id)<>NEW.delivery_id OR NEW.warehouse_delta<>0 OR NEW.custody_delta<>-(usage.quantity-COALESCE((SELECT quantity FROM workshop_part_usages WHERE id=usage.previous_usage_id),0)) OR NEW.consumed_delta<>(usage.quantity-COALESCE((SELECT quantity FROM workshop_part_usages WHERE id=usage.previous_usage_id),0)) OR NEW.actor_id<>usage.confirmed_by_id OR NEW.occurred_at<>usage.occurred_at THEN RAISE EXCEPTION 'Uso incompatible' USING ERRCODE='23514'; END IF;
   ELSE
    SELECT * INTO STRICT ret FROM inventory_returns WHERE id=NEW.return_id;
    IF ret.delivery_id<>delivery.id OR NEW.warehouse_delta<>ret.quantity OR NEW.custody_delta<>-ret.quantity OR NEW.consumed_delta<>0 OR NEW.actor_id<>ret.received_by_id OR NEW.occurred_at<>ret.occurred_at THEN RAISE EXCEPTION 'Devolución incompatible' USING ERRCODE='23514'; END IF;
   END IF;
  ELSE
   IF NEW.purchase_receipt_item_id IS NULL OR NEW.usage_id IS NOT NULL OR NEW.return_id IS NOT NULL OR NEW.application_id IS NOT NULL OR NEW.delivery_record_id IS NOT NULL OR NEW.reverses_movement_id IS NOT NULL OR NEW.warehouse_delta<=0 OR NEW.custody_delta<>0 OR NEW.consumed_delta<>0 OR NEW.delivery_id IS NOT NULL THEN RAISE EXCEPTION 'Recepción incompatible' USING ERRCODE='23514'; END IF;
   PERFORM workshop_require_actor(NEW.actor_id,ARRAY['administrator','secretary']);
  END IF;
  SELECT COALESCE(sum(warehouse_delta),0)+NEW.warehouse_delta INTO total FROM inventory_movements WHERE spare_part_id=NEW.spare_part_id;
  IF total<0 OR total>=1000000000000 THEN RAISE EXCEPTION 'Existencias insuficientes o fuera de rango' USING ERRCODE='23514'; END IF;
  IF COALESCE((SELECT sum(consumed_delta) FROM inventory_movements WHERE spare_part_id=NEW.spare_part_id),0)+NEW.consumed_delta<0 THEN RAISE EXCEPTION 'Consumo negativo' USING ERRCODE='23514'; END IF;
  IF NEW.delivery_id IS NOT NULL THEN
   SELECT COALESCE(sum(custody_delta),0)+NEW.custody_delta INTO total FROM inventory_movements WHERE delivery_id=NEW.delivery_id;
   IF total<0 THEN RAISE EXCEPTION 'Cantidad superior a custodia disponible' USING ERRCODE='23514'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION inventory_sources() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c inventory_corrections; d workshop_part_deliveries; p workshop_scope_parts; actor bigint; kind text; warehouse numeric; custody numeric; consumed numeric; source_delivery uuid; source_delivery_record uuid; difference numeric; source_usage uuid; source_return uuid; source_application uuid; reversal uuid;
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 UPDATE transport_assignment_guard SET version=version+1 WHERE id=1;
 IF TG_TABLE_NAME='inventory_applications' THEN
  SELECT r.* INTO STRICT c FROM inventory_decisions a JOIN inventory_corrections r ON r.id=a.correction_id WHERE a.id=NEW.decision_id;
  kind=c.kind; warehouse=c.quantity; custody=0; consumed=0; source_application=NEW.id; actor=NEW.applied_by_id; reversal=c.reverses_movement_id;
  INSERT INTO inventory_movements(id,spare_part_id,unit_snapshot,kind,warehouse_delta,custody_delta,consumed_delta,application_id,reverses_movement_id,actor_id,occurred_at) VALUES(gen_random_uuid(),c.spare_part_id,c.unit_snapshot,kind,warehouse,custody,consumed,source_application,reversal,actor,NEW.occurred_at);
  RETURN NEW;
 ELSIF TG_TABLE_NAME='workshop_part_deliveries' THEN
  IF NEW.previous_delivery_id IS NOT NULL THEN
   IF EXISTS(SELECT 1 FROM inventory_movements WHERE delivery_id=NEW.root_record_id) THEN RAISE EXCEPTION 'Entrega contabilizada: rectificación física requiere autorización específica' USING ERRCODE='23514'; END IF; RETURN NEW; END IF;
  difference=NEW.quantity-COALESCE((SELECT quantity FROM workshop_part_deliveries WHERE id=NEW.previous_delivery_id),0);
  d=NEW; source_delivery=NEW.root_record_id; source_delivery_record=NEW.id; kind='delivery'; warehouse=-difference; custody=difference; consumed=0; actor=NEW.recorded_by_id;
 ELSIF TG_TABLE_NAME='workshop_part_usages' THEN
  SELECT * INTO STRICT d FROM workshop_part_deliveries WHERE id=NEW.delivery_id;
  IF NEW.previous_usage_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM inventory_movements WHERE usage_id=NEW.root_record_id) THEN RETURN NEW; END IF;
  IF NOT EXISTS(SELECT 1 FROM inventory_movements m WHERE m.delivery_id=d.root_record_id AND m.kind='delivery') THEN RAISE EXCEPTION 'Entrega histórica no conciliada' USING ERRCODE='23514'; END IF;
  difference=NEW.quantity-COALESCE((SELECT quantity FROM workshop_part_usages WHERE id=NEW.previous_usage_id),0);
  IF NOT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=NEW.confirmed_by_id AND r.name='administrator') AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.id=NEW.mechanic_assignment_id AND e.user_id=NEW.confirmed_by_id) THEN RAISE EXCEPTION 'Solo el mecánico responsable confirma su uso' USING ERRCODE='23514'; END IF;
  source_delivery=d.root_record_id; source_usage=NEW.id; kind='usage'; warehouse=0; custody=-difference; consumed=difference; actor=NEW.confirmed_by_id;
 ELSE
  SELECT * INTO STRICT d FROM workshop_part_deliveries WHERE id=NEW.delivery_id;
  source_delivery=d.id; source_return=NEW.id; kind='return'; warehouse=NEW.quantity; custody=-NEW.quantity; consumed=0; actor=NEW.received_by_id;
 END IF;
 SELECT * INTO STRICT p FROM workshop_scope_parts WHERE id=d.scope_part_id;
 INSERT INTO inventory_movements(id,spare_part_id,unit_snapshot,kind,warehouse_delta,custody_delta,consumed_delta,delivery_id,delivery_record_id,usage_id,return_id,actor_id,occurred_at) VALUES(gen_random_uuid(),p.spare_part_id,p.unit_snapshot,kind,warehouse,custody,consumed,source_delivery,source_delivery_record,source_usage,source_return,actor,NEW.occurred_at);
 RETURN NEW;
END; $$;
CREATE TRIGGER inventory_stock AFTER INSERT ON workshop_part_deliveries FOR EACH ROW EXECUTE FUNCTION inventory_sources();
CREATE TRIGGER inventory_stock AFTER INSERT ON workshop_part_usages FOR EACH ROW EXECUTE FUNCTION inventory_sources();
CREATE TRIGGER inventory_stock AFTER INSERT ON inventory_returns FOR EACH ROW EXECUTE FUNCTION inventory_sources();
CREATE TRIGGER inventory_stock AFTER INSERT ON inventory_applications FOR EACH ROW EXECUTE FUNCTION inventory_sources();
CREATE FUNCTION inventory_unit_history() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 IF NEW.unit IS DISTINCT FROM OLD.unit AND EXISTS(SELECT 1 FROM inventory_movements WHERE spare_part_id=OLD.id) THEN RAISE EXCEPTION 'Unidad de inventario histórico inmutable' USING ERRCODE='23514'; END IF; RETURN NEW;
END; $$;
CREATE TRIGGER inventory_unit_history BEFORE UPDATE ON spare_parts FOR EACH ROW EXECUTE FUNCTION inventory_unit_history();
SQL);
        foreach (['inventory_operations', 'inventory_corrections', 'inventory_decisions', 'inventory_applications', 'inventory_returns', 'inventory_movements'] as $table) {
            DB::unprepared("CREATE TRIGGER inventory_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history(); CREATE TRIGGER inventory_no_truncate BEFORE TRUNCATE ON {$table} EXECUTE FUNCTION workshop_preserve_history();");
            if ($table !== 'inventory_operations') {
                DB::unprepared("CREATE TRIGGER inventory_validate BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION inventory_validate();");
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER inventory_stock ON workshop_part_deliveries; DROP TRIGGER inventory_stock ON workshop_part_usages; DROP TRIGGER inventory_unit_history ON spare_parts; ALTER TABLE inventory_corrections DROP CONSTRAINT inventory_corrections_reverses_movement_id_fkey; DROP TABLE inventory_movements, inventory_returns, inventory_applications, inventory_decisions, inventory_corrections, inventory_operations; DROP FUNCTION inventory_sources(), inventory_validate(), inventory_unit_history();');
    }
};
