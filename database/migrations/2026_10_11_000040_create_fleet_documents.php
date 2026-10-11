<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->boolean('tracks_expiration');
            $table->boolean('required_for_tractor_units');
            $table->boolean('required_for_tankers');
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('fleet_asset_id')->constrained()->restrictOnDelete();
            $table->text('storage_key');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('uploaded_by_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('registered_at')->useCurrent();
            $table->index(['fleet_asset_id', 'document_type_id', 'registered_at']);
            $table->index(['document_type_id', 'expires_on']);
            $table->index('uploaded_by_id');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE document_types ADD CONSTRAINT document_types_name_not_empty CHECK (btrim(name) <> '');
            ALTER TABLE documents ADD CONSTRAINT documents_storage_key_not_empty CHECK (btrim(storage_key) <> '');
            ALTER TABLE documents ADD CONSTRAINT documents_date_order CHECK (issued_on IS NULL OR expires_on IS NULL OR issued_on <= expires_on);

            CREATE FUNCTION fleet_document_validate_expiration() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE expiration_required boolean;
            BEGIN
                -- Serialize registration with configuration changes, including under obsolete snapshots.
                UPDATE document_types SET tracks_expiration = tracks_expiration
                    WHERE id = NEW.document_type_id RETURNING tracks_expiration INTO expiration_required;
                IF expiration_required AND NEW.expires_on IS NULL THEN
                    RAISE EXCEPTION 'Expiration date required' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER fleet_document_expiration BEFORE INSERT ON documents
                FOR EACH ROW EXECUTE FUNCTION fleet_document_validate_expiration();

            CREATE FUNCTION fleet_document_type_validate_expiration() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tracks_expiration AND EXISTS (SELECT 1 FROM documents WHERE document_type_id = NEW.id AND expires_on IS NULL) THEN
                    RAISE EXCEPTION 'Existing documents require expiration dates' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER fleet_document_type_expiration BEFORE UPDATE ON document_types
                FOR EACH ROW EXECUTE FUNCTION fleet_document_type_validate_expiration();

            CREATE FUNCTION fleet_document_preserve_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Fleet documents are append-only' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER fleet_document_history BEFORE UPDATE OR DELETE ON documents
                FOR EACH ROW EXECUTE FUNCTION fleet_document_preserve_history();
            CREATE TRIGGER fleet_document_history_truncate BEFORE TRUNCATE ON documents
                FOR EACH STATEMENT EXECUTE FUNCTION fleet_document_preserve_history();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
        DB::unprepared('DROP FUNCTION fleet_document_validate_expiration(); DROP FUNCTION fleet_document_type_validate_expiration(); DROP FUNCTION fleet_document_preserve_history();');
    }
};
