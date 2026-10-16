<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE document_alert_reviews (
 id uuid PRIMARY KEY, document_id uuid NOT NULL UNIQUE REFERENCES documents(id) ON DELETE RESTRICT,
 reviewed_by_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reviewed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 validity_at_review text NOT NULL CHECK(validity_at_review IN ('expired','expiring_soon'))
);
CREATE FUNCTION document_alert_validate_review() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 PERFORM 1 FROM users WHERE id=NEW.reviewed_by_id FOR UPDATE;
 PERFORM 1 FROM user_roles WHERE user_id=NEW.reviewed_by_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=NEW.reviewed_by_id AND u.is_active AND r.name IN ('administrator','secretary')) THEN
  RAISE EXCEPTION 'Revisión documental no autorizada' USING ERRCODE='23514';
 END IF;
 IF NOT EXISTS(SELECT 1 FROM current_fleet_documents c JOIN documents d ON d.id=c.document_id JOIN document_configuration_snapshots s ON s.document_id=d.id WHERE d.id=NEW.document_id AND s.tracks_expiration AND d.expires_on IS NOT NULL) THEN
  RAISE EXCEPTION 'Documento actual con vencimiento requerido' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER document_alert_review_validate BEFORE INSERT ON document_alert_reviews FOR EACH ROW EXECUTE FUNCTION document_alert_validate_review();
CREATE TRIGGER document_alert_review_history BEFORE UPDATE OR DELETE ON document_alert_reviews FOR EACH ROW EXECUTE FUNCTION fleet_document_preserve_history();
CREATE TRIGGER document_alert_review_no_truncate BEFORE TRUNCATE ON document_alert_reviews FOR EACH STATEMENT EXECUTE FUNCTION fleet_document_preserve_history();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE document_alert_reviews; DROP FUNCTION document_alert_validate_review();');
    }
};
