<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prevent registration/configuration racing the preflight check and DDL.
        DB::statement('LOCK TABLE documents, document_types IN SHARE ROW EXCLUSIVE MODE');
        if (DB::table('documents')->exists()) {
            throw new RuntimeException('Etapa 4.2: existen documentos sin configuración histórica ni selección actual verificables. Se requiere revisión explícita; no se inferirán estos datos.');
        }
        Schema::create('document_configuration_snapshots', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->text('name');
            $table->boolean('tracks_expiration');
            $table->boolean('required_for_tractor_units');
            $table->boolean('required_for_tankers');
        });
        Schema::create('current_fleet_documents', function (Blueprint $table) {
            $table->foreignUuid('fleet_asset_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('document_type_id')->constrained()->restrictOnDelete();
            $table->uuid('document_id')->unique();
            $table->primary(['fleet_asset_id', 'document_type_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_identity_unique UNIQUE (id, fleet_asset_id, document_type_id);
            ALTER TABLE current_fleet_documents ADD CONSTRAINT current_document_identity
                FOREIGN KEY (document_id, fleet_asset_id, document_type_id)
                REFERENCES documents (id, fleet_asset_id, document_type_id) ON DELETE RESTRICT;
            DROP TRIGGER fleet_document_type_expiration ON document_types;

            CREATE FUNCTION fleet_document_register_current() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- The existing BEFORE INSERT trigger locks the type and checks required expiration.
                INSERT INTO document_configuration_snapshots
                    SELECT NEW.id, name, tracks_expiration, required_for_tractor_units, required_for_tankers
                    FROM document_types WHERE id = NEW.document_type_id;
                INSERT INTO current_fleet_documents (fleet_asset_id, document_type_id, document_id)
                    VALUES (NEW.fleet_asset_id, NEW.document_type_id, NEW.id)
                    ON CONFLICT (fleet_asset_id, document_type_id) DO UPDATE SET document_id = EXCLUDED.document_id;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER fleet_document_current AFTER INSERT ON documents
                FOR EACH ROW EXECUTE FUNCTION fleet_document_register_current();

            CREATE TRIGGER document_snapshot_history BEFORE UPDATE OR DELETE ON document_configuration_snapshots
                FOR EACH ROW EXECUTE FUNCTION fleet_document_preserve_history();
            CREATE TRIGGER document_snapshot_truncate BEFORE TRUNCATE ON document_configuration_snapshots
                FOR EACH STATEMENT EXECUTE FUNCTION fleet_document_preserve_history();

            CREATE FUNCTION fleet_document_internal_reference() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF pg_trigger_depth() < 2 THEN
                    RAISE EXCEPTION 'Document references may only be written during document registration' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER document_snapshot_registration BEFORE INSERT ON document_configuration_snapshots
                FOR EACH ROW EXECUTE FUNCTION fleet_document_internal_reference();
            CREATE TRIGGER current_document_registration BEFORE INSERT OR UPDATE ON current_fleet_documents
                FOR EACH ROW EXECUTE FUNCTION fleet_document_internal_reference();
            CREATE TRIGGER current_document_preserve BEFORE DELETE ON current_fleet_documents
                FOR EACH ROW EXECUTE FUNCTION fleet_document_preserve_history();
            CREATE TRIGGER current_document_truncate BEFORE TRUNCATE ON current_fleet_documents
                FOR EACH STATEMENT EXECUTE FUNCTION fleet_document_preserve_history();
            SQL);
    }

    public function down(): void
    {
        // Restoring live-type validity would reinterpret history. Refuse an unsafe rollback.
        if (DB::table('documents')->exists()) {
            throw new RuntimeException('No revertir la etapa 4.2 con historial documental existente.');
        }
        DB::unprepared('DROP TRIGGER fleet_document_current ON documents');
        Schema::dropIfExists('current_fleet_documents');
        Schema::dropIfExists('document_configuration_snapshots');
        DB::unprepared(<<<'SQL'
            ALTER TABLE documents DROP CONSTRAINT documents_identity_unique;
            DROP FUNCTION fleet_document_register_current();
            DROP FUNCTION fleet_document_internal_reference();
            CREATE TRIGGER fleet_document_type_expiration BEFORE UPDATE ON document_types
                FOR EACH ROW EXECUTE FUNCTION fleet_document_type_validate_expiration();
            SQL);
    }
};
