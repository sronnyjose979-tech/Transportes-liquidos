<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('code')->unique();
            $table->text('type');
            $table->text('service_status')->default('available');
        });
        Schema::create('tractor_units', function (Blueprint $table) {
            $table->uuid('fleet_asset_id')->primary();
            $table->foreign('fleet_asset_id')->references('id')->on('fleet_assets');
        });
        Schema::create('tankers', function (Blueprint $table) {
            $table->uuid('fleet_asset_id')->primary();
            $table->boolean('has_physical_partitions');
            $table->foreign('fleet_asset_id')->references('id')->on('fleet_assets');
        });
        Schema::create('compartments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tanker_id');
            $table->text('code');
            $table->decimal('capacity_liters', 12, 2);
            $table->boolean('is_active');
            $table->foreign('tanker_id')->references('fleet_asset_id')->on('tankers');
            $table->unique(['tanker_id', 'code']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE fleet_assets ADD CONSTRAINT fleet_asset_type CHECK (type IN ('tractor_unit', 'tanker'));
ALTER TABLE fleet_assets ADD CONSTRAINT fleet_asset_service_status CHECK (service_status IN ('available', 'maintenance', 'out_of_service'));
ALTER TABLE fleet_assets ADD CONSTRAINT fleet_asset_code_nonempty CHECK (btrim(code) <> '');
ALTER TABLE compartments ADD CONSTRAINT compartment_code_nonempty CHECK (btrim(code) <> '');
ALTER TABLE compartments ADD CONSTRAINT compartment_capacity_positive CHECK (capacity_liters > 0 AND capacity_liters <> 'NaN'::numeric);

-- Every child mutation writes its aggregate root. Besides serializing READ
-- COMMITTED writers, this rejects obsolete REPEATABLE READ snapshots (40001).
CREATE FUNCTION fleet_lock_root() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    old_root uuid;
    new_root uuid;
    root_id uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        old_root := (to_jsonb(OLD)->>CASE WHEN TG_TABLE_NAME = 'compartments' THEN 'tanker_id' ELSE 'fleet_asset_id' END)::uuid;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        new_root := (to_jsonb(NEW)->>CASE WHEN TG_TABLE_NAME = 'compartments' THEN 'tanker_id' ELSE 'fleet_asset_id' END)::uuid;
    END IF;
    FOR root_id IN SELECT DISTINCT value FROM unnest(ARRAY[old_root, new_root]) AS roots(value) WHERE value IS NOT NULL ORDER BY value LOOP
        UPDATE fleet_assets SET id = id WHERE id = root_id;
    END LOOP;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION fleet_check_root(root_id uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    asset_type text;
    tractor_count integer;
    tanker_count integer;
    partitioned boolean;
    compartment_count integer;
BEGIN
    SELECT type INTO asset_type FROM fleet_assets WHERE id = root_id;
    IF NOT FOUND THEN RETURN; END IF;
    SELECT count(*) INTO tractor_count FROM tractor_units WHERE fleet_asset_id = root_id;
    SELECT count(*), bool_or(has_physical_partitions) INTO tanker_count, partitioned FROM tankers WHERE fleet_asset_id = root_id;
    IF NOT ((asset_type = 'tractor_unit' AND tractor_count = 1 AND tanker_count = 0)
        OR (asset_type = 'tanker' AND tractor_count = 0 AND tanker_count = 1)) THEN
        RAISE EXCEPTION 'Fleet asset requires exactly one specialization matching its type' USING ERRCODE = '23514', CONSTRAINT = 'fleet_specialization_total_xor';
    END IF;
    IF asset_type = 'tanker' THEN
        SELECT count(*) INTO compartment_count FROM compartments WHERE tanker_id = root_id;
        IF compartment_count < 1 OR (NOT partitioned AND compartment_count <> 1) THEN
            RAISE EXCEPTION 'Tanker requires compartments; without physical partitions it requires exactly one' USING ERRCODE = '23514', CONSTRAINT = 'tanker_compartment_cardinality';
        END IF;
    END IF;
END;
$$;

CREATE FUNCTION fleet_validate_roots() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    old_root uuid;
    new_root uuid;
    root_id uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        old_root := (to_jsonb(OLD)->>CASE WHEN TG_TABLE_NAME = 'fleet_assets' THEN 'id' WHEN TG_TABLE_NAME = 'compartments' THEN 'tanker_id' ELSE 'fleet_asset_id' END)::uuid;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        new_root := (to_jsonb(NEW)->>CASE WHEN TG_TABLE_NAME = 'fleet_assets' THEN 'id' WHEN TG_TABLE_NAME = 'compartments' THEN 'tanker_id' ELSE 'fleet_asset_id' END)::uuid;
    END IF;
    FOR root_id IN SELECT DISTINCT value FROM unnest(ARRAY[old_root, new_root]) AS roots(value) WHERE value IS NOT NULL ORDER BY value LOOP
        PERFORM fleet_check_root(root_id);
    END LOOP;
    RETURN NULL;
END;
$$;

-- TRUNCATE does not fire row triggers and cannot preserve mandatory children.
CREATE FUNCTION fleet_reject_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'TRUNCATE cannot preserve fleet aggregate integrity; use transactional DELETE' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER tractor_unit_reject_truncate BEFORE TRUNCATE ON tractor_units FOR EACH STATEMENT EXECUTE FUNCTION fleet_reject_truncate();
CREATE TRIGGER tanker_reject_truncate BEFORE TRUNCATE ON tankers FOR EACH STATEMENT EXECUTE FUNCTION fleet_reject_truncate();
CREATE TRIGGER compartment_reject_truncate BEFORE TRUNCATE ON compartments FOR EACH STATEMENT EXECUTE FUNCTION fleet_reject_truncate();

CREATE TRIGGER tractor_unit_lock_root BEFORE INSERT OR UPDATE OR DELETE ON tractor_units FOR EACH ROW EXECUTE FUNCTION fleet_lock_root();
CREATE TRIGGER tanker_lock_root BEFORE INSERT OR UPDATE OR DELETE ON tankers FOR EACH ROW EXECUTE FUNCTION fleet_lock_root();
CREATE TRIGGER compartment_lock_root BEFORE INSERT OR UPDATE OR DELETE ON compartments FOR EACH ROW EXECUTE FUNCTION fleet_lock_root();
CREATE CONSTRAINT TRIGGER fleet_asset_integrity AFTER INSERT OR UPDATE OR DELETE ON fleet_assets DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fleet_validate_roots();
CREATE CONSTRAINT TRIGGER tractor_unit_integrity AFTER INSERT OR UPDATE OR DELETE ON tractor_units DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fleet_validate_roots();
CREATE CONSTRAINT TRIGGER tanker_integrity AFTER INSERT OR UPDATE OR DELETE ON tankers DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fleet_validate_roots();
CREATE CONSTRAINT TRIGGER compartment_integrity AFTER INSERT OR UPDATE OR DELETE ON compartments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fleet_validate_roots();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('compartments');
        Schema::dropIfExists('tankers');
        Schema::dropIfExists('tractor_units');
        Schema::dropIfExists('fleet_assets');
        DB::unprepared('DROP FUNCTION IF EXISTS fleet_validate_roots(); DROP FUNCTION IF EXISTS fleet_check_root(uuid); DROP FUNCTION IF EXISTS fleet_lock_root(); DROP FUNCTION IF EXISTS fleet_reject_truncate();');
    }
};
