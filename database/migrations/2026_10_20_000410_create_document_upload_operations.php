<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE document_upload_operations (
                actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                request_key uuid NOT NULL,
                fleet_asset_id uuid NOT NULL REFERENCES fleet_assets(id) ON DELETE RESTRICT,
                renewed_document_id uuid REFERENCES documents(id) ON DELETE RESTRICT,
                document_id uuid NOT NULL UNIQUE REFERENCES documents(id) ON DELETE RESTRICT,
                fingerprint varchar(64) NOT NULL CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                registered_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                PRIMARY KEY (actor_id, request_key)
            );
            CREATE FUNCTION document_upload_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM documents d WHERE d.id = NEW.document_id
                    AND d.uploaded_by_id = NEW.actor_id AND d.fleet_asset_id = NEW.fleet_asset_id
                    AND (NEW.renewed_document_id IS NULL OR EXISTS (SELECT 1 FROM documents prior_document
                        WHERE prior_document.id = NEW.renewed_document_id AND prior_document.fleet_asset_id = d.fleet_asset_id
                        AND prior_document.document_type_id = d.document_type_id))) THEN
                    RAISE EXCEPTION 'Invalid document upload receipt' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER document_upload_consistency BEFORE INSERT ON document_upload_operations
                FOR EACH ROW EXECUTE FUNCTION document_upload_validate();
            CREATE TRIGGER document_upload_history BEFORE UPDATE OR DELETE ON document_upload_operations
                FOR EACH ROW EXECUTE FUNCTION fleet_document_preserve_history();
            CREATE TRIGGER document_upload_no_truncate BEFORE TRUNCATE ON document_upload_operations
                FOR EACH STATEMENT EXECUTE FUNCTION fleet_document_preserve_history();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Document upload receipts require an explicit audited rollback plan.');
    }
};
