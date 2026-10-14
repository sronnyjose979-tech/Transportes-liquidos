<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE billing_guard(id integer PRIMARY KEY CHECK(id=1),version bigint NOT NULL DEFAULT 0);
INSERT INTO billing_guard(id) VALUES(1);
CREATE TABLE billing_trip_amounts(id uuid PRIMARY KEY,trip_id uuid NOT NULL REFERENCES trips(id) ON DELETE RESTRICT,amount numeric NOT NULL CHECK(amount>=0 AND amount<=999999999999.99 AND scale(amount)<=2),currency text NOT NULL CHECK(currency IN('CRC','USD')),reason text NOT NULL CHECK(length(btrim(reason))>0),supersedes_id uuid UNIQUE REFERENCES billing_trip_amounts(id) ON DELETE RESTRICT,requested_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE billing_amount_decisions(id uuid PRIMARY KEY,amount_id uuid NOT NULL UNIQUE REFERENCES billing_trip_amounts(id) ON DELETE RESTRICT,decision text NOT NULL CHECK(decision IN('approved','rejected')),reason text NOT NULL CHECK(length(btrim(reason))>0),decided_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,decided_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE billing_invoices(id uuid PRIMARY KEY,customer_id uuid NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,customer_name_snapshot text NOT NULL,currency text NOT NULL CHECK(currency IN('CRC','USD')),reason text NOT NULL CHECK(length(btrim(reason))>0),created_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE billing_invoice_items(id uuid PRIMARY KEY,invoice_id uuid NOT NULL REFERENCES billing_invoices(id) ON DELETE RESTRICT,trip_id uuid NOT NULL UNIQUE REFERENCES trips(id) ON DELETE RESTRICT,price_proposal_id uuid NOT NULL UNIQUE REFERENCES billing_trip_amounts(id) ON DELETE RESTRICT,concept text NOT NULL CHECK(length(btrim(concept))>0),quantity numeric NOT NULL DEFAULT 1 CHECK(quantity=1),amount numeric NOT NULL CHECK(amount>=0 AND amount<=999999999999.99 AND scale(amount)<=2));
CREATE TABLE billing_payments(id uuid PRIMARY KEY,invoice_id uuid NOT NULL REFERENCES billing_invoices(id) ON DELETE RESTRICT,amount numeric NOT NULL CHECK(amount>0 AND amount<=999999999999.99 AND scale(amount)<=2),currency text NOT NULL CHECK(currency IN('CRC','USD')),paid_at timestamptz NOT NULL,reason text NOT NULL CHECK(length(btrim(reason))>0),method text CHECK(method IS NULL OR length(btrim(method)) BETWEEN 1 AND 255),recorded_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE billing_operations(id uuid PRIMARY KEY,actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,scope text NOT NULL,request_key uuid NOT NULL,fingerprint text NOT NULL CHECK(fingerprint~'^[a-f0-9]{64}$'),result jsonb NOT NULL,registered_at timestamptz NOT NULL DEFAULT clock_timestamp(),amount_id uuid UNIQUE REFERENCES billing_trip_amounts(id) ON DELETE RESTRICT,decision_id uuid UNIQUE REFERENCES billing_amount_decisions(id) ON DELETE RESTRICT,invoice_id uuid UNIQUE REFERENCES billing_invoices(id) ON DELETE RESTRICT,payment_id uuid UNIQUE REFERENCES billing_payments(id) ON DELETE RESTRICT,UNIQUE(actor_id,scope,request_key),CHECK(num_nonnulls(amount_id,decision_id,invoice_id,payment_id)=1));
CREATE INDEX billing_amount_trip ON billing_trip_amounts(trip_id,registered_at,id);
CREATE INDEX billing_invoice_customer ON billing_invoices(customer_id,registered_at,id);
CREATE INDEX billing_payment_invoice ON billing_payments(invoice_id,paid_at,id);
CREATE INDEX billing_items_invoice ON billing_invoice_items(invoice_id);
CREATE FUNCTION billing_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Historial financiero inmutable' USING ERRCODE='23514'; END; $$;
CREATE FUNCTION billing_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor bigint; administrator boolean; office boolean; price billing_trip_amounts; invoice billing_invoices; predecessor billing_trip_amounts;
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 UPDATE transport_assignment_guard SET version=version+1 WHERE id=1;
 UPDATE billing_guard SET version=version+1 WHERE id=1;
 IF TG_TABLE_NAME='billing_trip_amounts' THEN
  actor=NEW.requested_by_id;
  IF EXISTS(SELECT 1 FROM billing_invoice_items WHERE trip_id=NEW.trip_id) THEN RAISE EXCEPTION 'Viaje ya facturado; requiere procedimiento compensatorio' USING ERRCODE='23514'; END IF;
  IF NEW.supersedes_id IS NULL THEN
   IF EXISTS(SELECT 1 FROM billing_trip_amounts WHERE trip_id=NEW.trip_id) THEN RAISE EXCEPTION 'Corrección requiere antecedente exacto' USING ERRCODE='23514'; END IF;
  ELSE
   SELECT * INTO STRICT predecessor FROM billing_trip_amounts WHERE id=NEW.supersedes_id;
   IF predecessor.trip_id<>NEW.trip_id OR EXISTS(SELECT 1 FROM billing_trip_amounts WHERE supersedes_id=predecessor.id) THEN RAISE EXCEPTION 'Antecedente ajeno o sustituido' USING ERRCODE='23514'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='billing_amount_decisions' THEN
  actor=NEW.decided_by_id;
  SELECT * INTO STRICT price FROM billing_trip_amounts WHERE id=NEW.amount_id;
  IF EXISTS(SELECT 1 FROM billing_invoice_items WHERE trip_id=price.trip_id) OR EXISTS(SELECT 1 FROM billing_trip_amounts WHERE supersedes_id=price.id) THEN RAISE EXCEPTION 'Alcance sustituido o facturado' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='billing_invoices' THEN
  actor=NEW.created_by_id;
  IF NOT EXISTS(SELECT 1 FROM customers WHERE id=NEW.customer_id AND name=NEW.customer_name_snapshot) THEN RAISE EXCEPTION 'Cliente histórico incorrecto' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='billing_invoice_items' THEN
  SELECT * INTO STRICT invoice FROM billing_invoices WHERE id=NEW.invoice_id;
  SELECT * INTO STRICT price FROM billing_trip_amounts WHERE id=NEW.price_proposal_id;
  actor=invoice.created_by_id;
  PERFORM 1 FROM trips WHERE id=NEW.trip_id FOR UPDATE;
  IF EXISTS(SELECT 1 FROM billing_operations WHERE invoice_id=invoice.id) OR price.trip_id<>NEW.trip_id OR price.amount<>NEW.amount OR price.currency<>invoice.currency OR NOT EXISTS(SELECT 1 FROM billing_amount_decisions WHERE amount_id=price.id AND decision='approved') OR EXISTS(WITH RECURSIVE newer AS (SELECT id FROM billing_trip_amounts WHERE supersedes_id=price.id UNION ALL SELECT a.id FROM billing_trip_amounts a JOIN newer n ON a.supersedes_id=n.id) SELECT 1 FROM newer n JOIN billing_amount_decisions d ON d.amount_id=n.id WHERE d.decision='approved') OR NOT EXISTS(SELECT 1 FROM trips t JOIN transport_requests q ON q.id=t.transport_request_id WHERE t.id=NEW.trip_id AND t.execution_status='completed' AND t.officially_completed_at IS NOT NULL AND q.customer_id=invoice.customer_id) THEN RAISE EXCEPTION 'Viaje, moneda, importe o aprobación incompatible' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='billing_payments' THEN
  actor=NEW.recorded_by_id;
  SELECT * INTO STRICT invoice FROM billing_invoices WHERE id=NEW.invoice_id;
  IF NEW.currency<>invoice.currency OR NEW.amount+COALESCE((SELECT sum(amount) FROM billing_payments WHERE invoice_id=invoice.id),0)>COALESCE((SELECT sum(amount) FROM billing_invoice_items WHERE invoice_id=invoice.id),0) OR NOT EXISTS(SELECT 1 FROM billing_operations WHERE invoice_id=invoice.id) THEN RAISE EXCEPTION 'Pago supera saldo, factura no confirmada o moneda incompatible' USING ERRCODE='23514'; END IF;
 ELSE actor=NEW.actor_id;
 END IF;
 PERFORM 1 FROM users WHERE id=actor FOR UPDATE;
 PERFORM 1 FROM user_roles WHERE user_id=actor FOR UPDATE;
 SELECT EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=actor AND u.is_active AND r.name='administrator'),EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=actor AND u.is_active AND r.name IN('administrator','secretary')) INTO administrator,office;
 IF NOT office OR TG_TABLE_NAME='billing_amount_decisions' AND NOT administrator THEN RAISE EXCEPTION 'Responsable no autorizado' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION billing_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor bigint; target uuid; linked integer; expected_scope text;
BEGIN
 IF TG_TABLE_NAME='billing_operations' THEN
  IF NEW.amount_id IS NOT NULL THEN SELECT requested_by_id,'amount:'||trip_id INTO actor,expected_scope FROM billing_trip_amounts WHERE id=NEW.amount_id; target=NEW.amount_id;
  ELSIF NEW.decision_id IS NOT NULL THEN SELECT decided_by_id,'decision:'||amount_id INTO actor,expected_scope FROM billing_amount_decisions WHERE id=NEW.decision_id; target=NEW.decision_id;
  ELSIF NEW.invoice_id IS NOT NULL THEN SELECT created_by_id INTO actor FROM billing_invoices WHERE id=NEW.invoice_id; expected_scope='invoice'; target=NEW.invoice_id;
  ELSE SELECT recorded_by_id,'payment:'||invoice_id INTO actor,expected_scope FROM billing_payments WHERE id=NEW.payment_id; target=NEW.payment_id; END IF;
  IF actor IS DISTINCT FROM NEW.actor_id OR NEW.scope IS DISTINCT FROM expected_scope OR NEW.result<>jsonb_build_object(CASE WHEN NEW.amount_id IS NOT NULL THEN 'amount_id' WHEN NEW.decision_id IS NOT NULL THEN 'decision_id' WHEN NEW.invoice_id IS NOT NULL THEN 'invoice_id' ELSE 'payment_id' END,target) THEN RAISE EXCEPTION 'Recibo idempotente incompatible' USING ERRCODE='23514'; END IF;
 ELSE
  IF TG_TABLE_NAME='billing_trip_amounts' THEN SELECT count(*) INTO linked FROM billing_operations WHERE amount_id=NEW.id AND actor_id=NEW.requested_by_id;
  ELSIF TG_TABLE_NAME='billing_amount_decisions' THEN SELECT count(*) INTO linked FROM billing_operations WHERE decision_id=NEW.id AND actor_id=NEW.decided_by_id;
  ELSIF TG_TABLE_NAME='billing_invoices' THEN
   SELECT count(*) INTO linked FROM billing_operations WHERE invoice_id=NEW.id AND actor_id=NEW.created_by_id;
   IF NOT EXISTS(SELECT 1 FROM billing_invoice_items WHERE invoice_id=NEW.id) OR (SELECT count(*) FROM billing_invoice_items WHERE invoice_id=NEW.id)>100 THEN RAISE EXCEPTION 'Factura sin detalles' USING ERRCODE='23514'; END IF;
  ELSE SELECT count(*) INTO linked FROM billing_operations WHERE payment_id=NEW.id AND actor_id=NEW.recorded_by_id; END IF;
  IF linked<>1 THEN RAISE EXCEPTION 'Operación sin auditoría idempotente' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NULL;
END; $$;
SQL);
        foreach (['billing_trip_amounts', 'billing_amount_decisions', 'billing_invoices', 'billing_invoice_items', 'billing_payments', 'billing_operations'] as $table) {
            DB::unprepared("CREATE TRIGGER billing_validate BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION billing_validate(); CREATE TRIGGER billing_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION billing_immutable(); CREATE TRIGGER billing_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION billing_immutable();");
            if ($table !== 'billing_invoice_items') {
                DB::unprepared("CREATE CONSTRAINT TRIGGER billing_complete AFTER INSERT ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION billing_complete();");
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE billing_operations,billing_payments,billing_invoice_items,billing_invoices,billing_amount_decisions,billing_trip_amounts,billing_guard; DROP FUNCTION billing_complete(),billing_validate(),billing_immutable();');
    }
};
