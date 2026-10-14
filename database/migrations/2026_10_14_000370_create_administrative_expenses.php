<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE expenses(id uuid PRIMARY KEY,trip_id uuid REFERENCES trips(id) ON DELETE RESTRICT,created_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE expense_revisions(id uuid PRIMARY KEY,expense_id uuid NOT NULL REFERENCES expenses(id) ON DELETE RESTRICT,supersedes_id uuid UNIQUE REFERENCES expense_revisions(id) ON DELETE RESTRICT,concept text NOT NULL CHECK(length(btrim(concept)) BETWEEN 1 AND 255),category text NOT NULL CHECK(length(btrim(category)) BETWEEN 1 AND 255),amount numeric NOT NULL CHECK(amount>0 AND amount<=999999999999.99 AND scale(amount)<=2),currency text NOT NULL CHECK(currency IN('CRC','USD')),occurred_at timestamptz NOT NULL,description text,reason text,recorded_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE UNIQUE INDEX expense_initial_revision ON expense_revisions(expense_id) WHERE supersedes_id IS NULL;
CREATE TABLE expense_decisions(id uuid PRIMARY KEY,revision_id uuid NOT NULL UNIQUE REFERENCES expense_revisions(id) ON DELETE RESTRICT,decision text NOT NULL CHECK(decision IN('approved','rejected')),reason text NOT NULL CHECK(length(btrim(reason)) BETWEEN 1 AND 2000),decided_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,decided_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE expense_receipts(id uuid PRIMARY KEY,expense_id uuid NOT NULL REFERENCES expenses(id) ON DELETE RESTRICT,storage_key text NOT NULL UNIQUE CHECK(storage_key ~ '^expenses/files/[a-f0-9-]{36}\.(pdf|jpg|png)$'),mime_type text NOT NULL CHECK(mime_type IN('application/pdf','image/jpeg','image/png')),size_bytes bigint NOT NULL CHECK(size_bytes BETWEEN 1 AND 10000000),sha256 text NOT NULL CHECK(sha256 ~ '^[a-f0-9]{64}$'),uploaded_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,registered_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE expense_operations(id uuid PRIMARY KEY,actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,scope text NOT NULL,request_key uuid NOT NULL,fingerprint text NOT NULL CHECK(fingerprint~'^[a-f0-9]{64}$'),result jsonb NOT NULL,revision_id uuid UNIQUE REFERENCES expense_revisions(id) ON DELETE RESTRICT,decision_id uuid UNIQUE REFERENCES expense_decisions(id) ON DELETE RESTRICT,receipt_id uuid UNIQUE REFERENCES expense_receipts(id) ON DELETE RESTRICT,registered_at timestamptz NOT NULL DEFAULT clock_timestamp(),UNIQUE(actor_id,scope,request_key),CHECK(num_nonnulls(revision_id,decision_id,receipt_id)=1));
CREATE INDEX expenses_trip ON expenses(trip_id,registered_at);
CREATE INDEX expenses_actor ON expenses(created_by_id,registered_at);
CREATE INDEX expense_versions ON expense_revisions(expense_id,registered_at);
CREATE INDEX expense_files ON expense_receipts(expense_id);
CREATE FUNCTION expense_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor bigint; office boolean; admin boolean; target expenses; revision expense_revisions; linked integer;
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 UPDATE transport_assignment_guard SET version=version+1 WHERE id=1;
 IF TG_TABLE_NAME='expenses' THEN actor=NEW.created_by_id; target=NEW;
 ELSIF TG_TABLE_NAME='expense_revisions' THEN actor=NEW.recorded_by_id; SELECT * INTO STRICT target FROM expenses WHERE id=NEW.expense_id;
  IF NEW.supersedes_id IS NULL THEN
   IF actor<>target.created_by_id THEN RAISE EXCEPTION 'Autor inicial incompatible' USING ERRCODE='23514'; END IF;
  ELSE
   SELECT * INTO STRICT revision FROM expense_revisions WHERE id=NEW.supersedes_id;
   IF revision.expense_id<>NEW.expense_id OR NEW.reason IS NULL OR length(btrim(NEW.reason))=0 THEN RAISE EXCEPTION 'Corrección incompatible' USING ERRCODE='23514'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='expense_decisions' THEN actor=NEW.decided_by_id;
  IF EXISTS(SELECT 1 FROM expense_revisions WHERE supersedes_id=NEW.revision_id) THEN RAISE EXCEPTION 'Versión sustituida' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='expense_receipts' THEN actor=NEW.uploaded_by_id; SELECT * INTO STRICT target FROM expenses WHERE id=NEW.expense_id;
 ELSE actor=NEW.actor_id;
 END IF;
 PERFORM 1 FROM users WHERE id=actor FOR UPDATE;
 PERFORM 1 FROM user_roles WHERE user_id=actor FOR UPDATE;
 SELECT EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=actor AND u.is_active AND r.name IN('administrator','secretary')),EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=actor AND u.is_active AND r.name='administrator') INTO office,admin;
 IF TG_TABLE_NAME='expense_decisions' AND NOT admin THEN RAISE EXCEPTION 'Decisión reservada' USING ERRCODE='23514'; END IF;
 IF NOT office THEN
  IF TG_TABLE_NAME IN('expenses','expense_revisions','expense_receipts') THEN
   IF TG_TABLE_NAME='expense_revisions' THEN IF NEW.supersedes_id IS NOT NULL THEN RAISE EXCEPTION 'Corrección reservada' USING ERRCODE='23514'; END IF; END IF;
   IF target.created_by_id<>actor OR target.trip_id IS NULL OR NOT EXISTS(SELECT 1 FROM driver_assignments a JOIN employees e ON e.id=a.employee_id JOIN users u ON u.id=e.user_id JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE a.trip_id=target.trip_id AND e.user_id=actor AND e.is_active AND u.is_active AND r.name='driver' AND a.starts_at<=clock_timestamp() AND NOT EXISTS(SELECT 1 FROM driver_releases x WHERE x.assignment_id=a.id)) THEN RAISE EXCEPTION 'Asignación o responsable incompatible' USING ERRCODE='23514'; END IF;
  ELSIF TG_TABLE_NAME<>'expense_operations' THEN RAISE EXCEPTION 'Responsable no autorizado' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION expense_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor bigint; target uuid; expected_scope text; expected_key text; linked integer; expense uuid;
BEGIN
 IF TG_TABLE_NAME='expense_operations' THEN
  IF NEW.revision_id IS NOT NULL THEN SELECT recorded_by_id,CASE WHEN supersedes_id IS NULL THEN 'create' ELSE 'correction:'||expense_id END INTO actor,expected_scope FROM expense_revisions WHERE id=NEW.revision_id; target=NEW.revision_id; expected_key='revision_id'; SELECT expense_id INTO expense FROM expense_revisions WHERE id=target;
  ELSIF NEW.decision_id IS NOT NULL THEN SELECT d.decided_by_id,'decision:'||v.expense_id INTO actor,expected_scope FROM expense_decisions d JOIN expense_revisions v ON v.id=d.revision_id WHERE d.id=NEW.decision_id; target=NEW.decision_id; expected_key='decision_id'; SELECT v.expense_id INTO expense FROM expense_decisions d JOIN expense_revisions v ON v.id=d.revision_id WHERE d.id=target;
  ELSE SELECT uploaded_by_id,'receipt:'||expense_id INTO actor,expected_scope FROM expense_receipts WHERE id=NEW.receipt_id; target=NEW.receipt_id; expected_key='receipt_id'; SELECT expense_id INTO expense FROM expense_receipts WHERE id=target; END IF;
  IF actor IS DISTINCT FROM NEW.actor_id OR expected_scope IS DISTINCT FROM NEW.scope OR NEW.result IS DISTINCT FROM jsonb_build_object('expense_id',expense,expected_key,target) THEN RAISE EXCEPTION 'Recibo idempotente incompatible' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='expenses' THEN
  IF NOT EXISTS(SELECT 1 FROM expense_revisions WHERE expense_id=NEW.id AND supersedes_id IS NULL) THEN RAISE EXCEPTION 'Gasto sin registro' USING ERRCODE='23514'; END IF;
 ELSE
  IF TG_TABLE_NAME='expense_revisions' THEN SELECT count(*) INTO linked FROM expense_operations WHERE revision_id=NEW.id AND actor_id=NEW.recorded_by_id;
  ELSIF TG_TABLE_NAME='expense_decisions' THEN SELECT count(*) INTO linked FROM expense_operations WHERE decision_id=NEW.id AND actor_id=NEW.decided_by_id;
  ELSE SELECT count(*) INTO linked FROM expense_operations WHERE receipt_id=NEW.id AND actor_id=NEW.uploaded_by_id; END IF;
  IF linked<>1 THEN RAISE EXCEPTION 'Registro sin auditoría' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NULL;
END; $$;
DO $$ DECLARE t text; BEGIN FOREACH t IN ARRAY ARRAY['expenses','expense_revisions','expense_decisions','expense_receipts','expense_operations'] LOOP
 EXECUTE format('CREATE TRIGGER expense_record BEFORE INSERT ON %I FOR EACH ROW EXECUTE FUNCTION expense_validate()',t);
 EXECUTE format('CREATE TRIGGER expense_history BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION transport_preserve_history()',t);
 EXECUTE format('CREATE TRIGGER expense_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history()',t);
 EXECUTE format('CREATE CONSTRAINT TRIGGER expense_audit AFTER INSERT ON %I DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION expense_complete()',t);
END LOOP; END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE expense_operations,expense_receipts,expense_decisions,expense_revisions,expenses; DROP FUNCTION expense_validate(),expense_complete();');
    }
};
