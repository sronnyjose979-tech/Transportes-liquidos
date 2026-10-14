<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE purchase_orders (id uuid PRIMARY KEY,purchase_request_id uuid NOT NULL REFERENCES purchase_requests(id) ON DELETE RESTRICT,supplier_id uuid NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,reason text NOT NULL CHECK(length(btrim(reason))>0),created_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE purchase_order_items (id uuid PRIMARY KEY,purchase_order_id uuid NOT NULL REFERENCES purchase_orders(id) ON DELETE RESTRICT,purchase_request_item_id uuid NOT NULL REFERENCES purchase_request_items(id) ON DELETE RESTRICT,quantity numeric NOT NULL CHECK(quantity>0 AND quantity<=999999999999.999999 AND scale(quantity)<=6),UNIQUE(purchase_order_id,purchase_request_item_id));
CREATE TABLE purchase_receipts (id uuid PRIMARY KEY,purchase_order_id uuid NOT NULL REFERENCES purchase_orders(id) ON DELETE RESTRICT,received_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,received_at timestamptz NOT NULL,reason text NOT NULL CHECK(length(btrim(reason))>0),registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE purchase_receipt_items (id uuid PRIMARY KEY,purchase_receipt_id uuid NOT NULL REFERENCES purchase_receipts(id) ON DELETE RESTRICT,purchase_order_item_id uuid NOT NULL REFERENCES purchase_order_items(id) ON DELETE RESTRICT,quantity numeric NOT NULL CHECK(quantity>0 AND quantity<=999999999999.999999 AND scale(quantity)<=6),UNIQUE(purchase_receipt_id,purchase_order_item_id));
CREATE TABLE purchase_fulfillment_operations (id uuid PRIMARY KEY,actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,scope text NOT NULL CHECK(scope IN ('order_create','receipt_create')),request_key uuid NOT NULL,fingerprint text NOT NULL CHECK(fingerprint~'^[a-f0-9]{64}$'),purchase_order_id uuid NOT NULL REFERENCES purchase_orders(id) ON DELETE RESTRICT,purchase_receipt_id uuid UNIQUE REFERENCES purchase_receipts(id) ON DELETE RESTRICT,registered_at timestamptz NOT NULL DEFAULT clock_timestamp(),UNIQUE(actor_id,scope,request_key),CHECK((scope='order_create' AND purchase_receipt_id IS NULL) OR (scope='receipt_create' AND purchase_receipt_id IS NOT NULL)));
CREATE UNIQUE INDEX purchase_order_creation ON purchase_fulfillment_operations(purchase_order_id) WHERE scope='order_create';
CREATE INDEX purchase_orders_request ON purchase_orders(purchase_request_id);
CREATE INDEX purchase_order_items_request ON purchase_order_items(purchase_request_item_id);
CREATE INDEX purchase_receipts_order ON purchase_receipts(purchase_order_id);
CREATE INDEX purchase_receipt_items_order ON purchase_receipt_items(purchase_order_item_id);
CREATE FUNCTION purchasing_fulfillment_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor bigint; target purchase_orders; requested purchase_request_items; ordered purchase_order_items;
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 UPDATE transport_assignment_guard SET version=version+1 WHERE id=1;
 IF TG_TABLE_NAME='purchase_orders' THEN
  actor=NEW.created_by_id;
  IF NOT EXISTS(SELECT 1 FROM purchase_requests q JOIN purchase_request_decisions d ON d.purchase_request_id=q.id WHERE q.id=NEW.purchase_request_id AND q.supplier_id=NEW.supplier_id AND d.decision='approved') THEN RAISE EXCEPTION 'Solicitud no autorizada para proveedor' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='purchase_order_items' THEN
  SELECT * INTO STRICT target FROM purchase_orders WHERE id=NEW.purchase_order_id;
  SELECT * INTO STRICT requested FROM purchase_request_items WHERE id=NEW.purchase_request_item_id;
  IF requested.purchase_request_id<>target.purchase_request_id OR EXISTS(SELECT 1 FROM purchase_fulfillment_operations WHERE purchase_order_id=target.id AND scope='order_create') OR NEW.quantity+COALESCE((SELECT sum(quantity) FROM purchase_order_items WHERE purchase_request_item_id=requested.id),0)>requested.quantity THEN RAISE EXCEPTION 'Cantidad no autorizada o alcance confirmado' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 ELSIF TG_TABLE_NAME='purchase_receipts' THEN actor=NEW.received_by_id;
 ELSIF TG_TABLE_NAME='purchase_receipt_items' THEN
  SELECT * INTO STRICT ordered FROM purchase_order_items WHERE id=NEW.purchase_order_item_id;
  IF NOT EXISTS(SELECT 1 FROM purchase_receipts WHERE id=NEW.purchase_receipt_id AND purchase_order_id=ordered.purchase_order_id) OR EXISTS(SELECT 1 FROM purchase_fulfillment_operations WHERE purchase_receipt_id=NEW.purchase_receipt_id) OR NEW.quantity+COALESCE((SELECT sum(quantity) FROM purchase_receipt_items WHERE purchase_order_item_id=ordered.id),0)>ordered.quantity THEN RAISE EXCEPTION 'Recepción ajena, confirmada o superior a autorización' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 ELSE actor=NEW.actor_id;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=actor AND u.is_active AND r.name IN ('administrator','secretary')) THEN RAISE EXCEPTION 'Responsable no habilitado' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
ALTER TABLE inventory_movements ADD CONSTRAINT inventory_receipt_source FOREIGN KEY(purchase_receipt_item_id) REFERENCES purchase_receipt_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
CREATE FUNCTION purchasing_receipt_stock_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE receipt purchase_receipts; ordered purchase_order_items; requested purchase_request_items; item purchase_receipt_items;
BEGIN
 IF TG_TABLE_NAME='purchase_receipt_items' THEN item=NEW;
 ELSE
  IF NEW.kind='receipt' AND NEW.purchase_receipt_item_id IS NULL THEN RAISE EXCEPTION 'Recepción sin origen de compra' USING ERRCODE='23514'; END IF;
  SELECT * INTO item FROM purchase_receipt_items WHERE id=NEW.purchase_receipt_item_id;
  IF NOT FOUND THEN RETURN NULL; END IF;
 END IF;
 SELECT * INTO STRICT receipt FROM purchase_receipts WHERE id=item.purchase_receipt_id;
 SELECT * INTO STRICT ordered FROM purchase_order_items WHERE id=item.purchase_order_item_id;
 SELECT * INTO STRICT requested FROM purchase_request_items WHERE id=ordered.purchase_request_item_id;
 IF NOT EXISTS(SELECT 1 FROM inventory_movements m WHERE m.purchase_receipt_item_id=item.id AND m.kind='receipt' AND m.spare_part_id=requested.spare_part_id AND m.unit_snapshot=requested.unit_snapshot AND m.warehouse_delta=item.quantity AND m.custody_delta=0 AND m.consumed_delta=0 AND m.actor_id=receipt.received_by_id AND m.occurred_at=receipt.received_at) THEN RAISE EXCEPTION 'Recepción sin entrada exacta de inventario' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER purchasing_receipt_stock_complete AFTER INSERT ON purchase_receipt_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION purchasing_receipt_stock_complete();
CREATE CONSTRAINT TRIGGER purchasing_receipt_stock_complete AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION purchasing_receipt_stock_complete();
CREATE FUNCTION purchasing_fulfillment_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='purchase_orders' THEN
  IF NOT EXISTS(SELECT 1 FROM purchase_order_items WHERE purchase_order_id=NEW.id) OR NOT EXISTS(SELECT 1 FROM purchase_fulfillment_operations WHERE purchase_order_id=NEW.id AND scope='order_create' AND actor_id=NEW.created_by_id) THEN RAISE EXCEPTION 'Orden incompleta o no auditada' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='purchase_receipts' THEN
  IF NOT EXISTS(SELECT 1 FROM purchase_receipt_items WHERE purchase_receipt_id=NEW.id) OR NOT EXISTS(SELECT 1 FROM purchase_fulfillment_operations WHERE purchase_receipt_id=NEW.id AND scope='receipt_create' AND purchase_order_id=NEW.purchase_order_id AND actor_id=NEW.received_by_id) THEN RAISE EXCEPTION 'Recepción incompleta o no auditada' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='purchase_fulfillment_operations' THEN
  IF NEW.scope='order_create' AND NOT EXISTS(SELECT 1 FROM purchase_orders WHERE id=NEW.purchase_order_id AND created_by_id=NEW.actor_id) OR NEW.scope='receipt_create' AND NOT EXISTS(SELECT 1 FROM purchase_receipts WHERE id=NEW.purchase_receipt_id AND purchase_order_id=NEW.purchase_order_id AND received_by_id=NEW.actor_id) THEN RAISE EXCEPTION 'Operación incompatible' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NULL;
END; $$;
SQL);
        foreach (['purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items', 'purchase_fulfillment_operations'] as $table) {
            DB::unprepared("CREATE TRIGGER purchasing_validate BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION purchasing_fulfillment_validate(); CREATE TRIGGER purchasing_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION purchasing_immutable(); CREATE TRIGGER purchasing_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION purchasing_immutable();");
        }
        foreach (['purchase_orders', 'purchase_receipts', 'purchase_fulfillment_operations'] as $table) {
            DB::unprepared("CREATE CONSTRAINT TRIGGER purchasing_complete AFTER INSERT ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION purchasing_fulfillment_complete();");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER purchasing_receipt_stock_complete ON inventory_movements; ALTER TABLE inventory_movements DROP CONSTRAINT inventory_receipt_source; DROP TABLE purchase_fulfillment_operations,purchase_receipt_items,purchase_receipts,purchase_order_items,purchase_orders; DROP FUNCTION purchasing_receipt_stock_complete(),purchasing_fulfillment_complete(),purchasing_fulfillment_validate();');
    }
};
