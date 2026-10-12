<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_loads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->unique('trip_id');
            $table->foreignUuid('tanker_assignment_id')->constrained('trip_asset_assignments')->restrictOnDelete();
            $table->foreignUuid('operation_site_id')->constrained('operation_sites')->restrictOnDelete();
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->jsonb('evidence')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('registered_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('occurred_at', 6);
        });
        Schema::create('load_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_load_id')->constrained('trip_loads')->restrictOnDelete();
            $table->foreignUuid('compartment_id')->constrained('compartments')->restrictOnDelete();
            $table->decimal('loaded_liters', 15, 2);
            $table->decimal('capacity_liters_snapshot', 15, 2);
            $table->text('compartment_code_snapshot');
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->jsonb('evidence')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('registered_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('occurred_at', 6);
            $table->unique(['trip_load_id', 'compartment_id']);
        });
        Schema::create('trip_unloads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->unique('trip_id');
            $table->foreignUuid('trip_load_id')->constrained('trip_loads')->restrictOnDelete();
            $table->unique('trip_load_id');
            $table->foreignUuid('operation_site_id')->constrained('operation_sites')->restrictOnDelete();
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->jsonb('evidence')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('registered_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('occurred_at', 6);
        });
        Schema::create('unload_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_unload_id')->constrained('trip_unloads')->restrictOnDelete();
            $table->foreignUuid('load_item_id')->constrained('load_items')->restrictOnDelete();
            $table->unique('load_item_id');
            $table->decimal('delivered_liters', 15, 2);
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->jsonb('evidence')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('registered_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('occurred_at', 6);
        });
        Schema::create('trip_odometer_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUuid('tractor_assignment_id')->constrained('trip_asset_assignments')->restrictOnDelete();
            $table->text('reading_type');
            $table->decimal('reading_value', 15, 2);
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->jsonb('evidence')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('registered_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->timestampTz('occurred_at', 6);
            $table->uuid('previous_reading_id')->nullable();
            $table->unique('previous_reading_id');
            $table->bigInteger('revision')->default(1);
            $table->index(['trip_id', 'tractor_assignment_id', 'reading_type']);
        });
        Schema::table('trip_odometer_readings', function (Blueprint $table) {
            $table->foreign('previous_reading_id')->references('id')->on('trip_odometer_readings')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE trip_odometer_readings ALTER COLUMN reading_value TYPE numeric;
            ALTER TABLE load_items ADD CONSTRAINT trip_load_volume CHECK (loaded_liters>=0 AND loaded_liters<>'NaN'::numeric AND loaded_liters<=capacity_liters_snapshot),
                ADD CONSTRAINT trip_capacity_snapshot CHECK (capacity_liters_snapshot>0 AND capacity_liters_snapshot<>'NaN'::numeric);
            ALTER TABLE unload_items ADD CONSTRAINT trip_unload_volume CHECK (delivered_liters>=0 AND delivered_liters<>'NaN'::numeric);
            ALTER TABLE trip_odometer_readings ADD CONSTRAINT trip_odometer_value CHECK (reading_value>=0 AND reading_value NOT IN ('NaN'::numeric,'Infinity'::numeric,'-Infinity'::numeric)),
                ADD CONSTRAINT trip_odometer_type CHECK (reading_type IN ('initial','final')), ADD CONSTRAINT trip_odometer_revision CHECK (revision>0);
            CREATE UNIQUE INDEX trip_odometer_original ON trip_odometer_readings(trip_id,tractor_assignment_id,reading_type) WHERE previous_reading_id IS NULL;

            CREATE FUNCTION trip_operation_actor(actor bigint,target uuid,driver_allowed boolean DEFAULT false) RETURNS void LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(873210);
                PERFORM 1 FROM users WHERE id=actor FOR UPDATE;
                PERFORM 1 FROM user_roles WHERE user_id=actor FOR UPDATE;
                IF EXISTS (SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=actor AND u.is_active AND r.name IN ('administrator','secretary')) THEN RETURN; END IF;
                IF driver_allowed AND EXISTS (SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
                    JOIN employees e ON e.user_id=u.id JOIN driver_assignments a ON a.employee_id=e.id
                    WHERE u.id=actor AND u.is_active AND e.is_active AND r.name='driver' AND a.trip_id=target
                    AND NOT EXISTS (SELECT 1 FROM driver_releases x WHERE x.assignment_id=a.id)) THEN RETURN; END IF;
                RAISE EXCEPTION 'Trip operation actor not authorized' USING ERRCODE='23514';
            END; $$;

            CREATE FUNCTION trip_validate_official_start() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.execution_status='in_progress' AND OLD.execution_status IS DISTINCT FROM 'in_progress' THEN
                    PERFORM trip_operation_actor(NEW.started_by_id,NEW.id);
                    IF NOT EXISTS (SELECT 1 FROM driver_assignments a JOIN employees e ON e.id=a.employee_id JOIN users u ON u.id=e.user_id
                        WHERE a.trip_id=NEW.id AND e.is_active AND u.is_active AND EXISTS
                            (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.name='driver')
                        AND NOT EXISTS (SELECT 1 FROM driver_releases x WHERE x.assignment_id=a.id))
                        OR (SELECT count(*) FROM trip_asset_assignments a JOIN fleet_assets f ON f.id=a.fleet_asset_id
                            WHERE a.trip_id=NEW.id AND f.type=a.asset_type AND f.service_status='available'
                            AND NOT EXISTS (SELECT 1 FROM trip_asset_releases x WHERE x.assignment_id=a.id))<>2 THEN
                        RAISE EXCEPTION 'Official start requires eligible current assignments' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER trip_official_start BEFORE UPDATE ON trips FOR EACH ROW EXECUTE FUNCTION trip_validate_official_start();

            CREATE FUNCTION trip_operation_record() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE target uuid; tanker uuid; compartment compartments; original trip_odometer_readings;
            BEGIN
                IF TG_TABLE_NAME='trip_events' THEN
                    PERFORM trip_operation_actor(NEW.created_by_id,NEW.trip_id,true);
                    RETURN NEW;
                END IF;
                IF TG_TABLE_NAME IN ('trip_loads','trip_unloads','trip_odometer_readings') THEN target=NEW.trip_id;
                ELSIF TG_TABLE_NAME='load_items' THEN SELECT trip_id INTO target FROM trip_loads WHERE id=NEW.trip_load_id;
                ELSE SELECT trip_id INTO target FROM trip_unloads WHERE id=NEW.trip_unload_id; END IF;
                PERFORM trip_operation_actor(NEW.recorded_by_id,target);
                PERFORM 1 FROM trips WHERE id=target FOR UPDATE;
                IF btrim(NEW.reason)='' OR jsonb_typeof(NEW.evidence)<>'object' THEN RAISE EXCEPTION 'Operation requires reason and object evidence' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='trip_loads' THEN
                    IF NOT EXISTS (SELECT 1 FROM trip_asset_assignments a WHERE a.id=NEW.tanker_assignment_id AND a.trip_id=target AND a.asset_type='tanker'
                        AND NOT EXISTS (SELECT 1 FROM trip_asset_releases x WHERE x.assignment_id=a.id)) THEN RAISE EXCEPTION 'Load requires current assigned tanker' USING ERRCODE='23514'; END IF;
                ELSIF TG_TABLE_NAME='load_items' THEN
                    SELECT a.fleet_asset_id INTO tanker FROM trip_loads l JOIN trip_asset_assignments a ON a.id=l.tanker_assignment_id WHERE l.id=NEW.trip_load_id;
                    SELECT * INTO compartment FROM compartments WHERE id=NEW.compartment_id FOR UPDATE;
                    IF compartment.id IS NULL OR compartment.tanker_id IS DISTINCT FROM tanker OR NOT compartment.is_active THEN RAISE EXCEPTION 'Invalid loading compartment' USING ERRCODE='23514'; END IF;
                    NEW.capacity_liters_snapshot=compartment.capacity_liters;
                    NEW.compartment_code_snapshot=compartment.code;
                ELSIF TG_TABLE_NAME='trip_unloads' THEN
                    IF NOT EXISTS (SELECT 1 FROM trip_loads WHERE id=NEW.trip_load_id AND trip_id=target) THEN RAISE EXCEPTION 'Unload must refer to same trip load' USING ERRCODE='23514'; END IF;
                ELSIF TG_TABLE_NAME='unload_items' THEN
                    IF NOT EXISTS (SELECT 1 FROM load_items i JOIN trip_unloads u ON u.trip_load_id=i.trip_load_id WHERE i.id=NEW.load_item_id AND u.id=NEW.trip_unload_id) THEN RAISE EXCEPTION 'Unload item belongs to another trip' USING ERRCODE='23514'; END IF;
                    IF NEW.delivered_liters>(SELECT capacity_liters_snapshot FROM load_items WHERE id=NEW.load_item_id) THEN
                        RAISE EXCEPTION 'Delivered liters exceed recorded compartment capacity' USING ERRCODE='23514';
                    END IF;
                ELSE
                    IF NOT EXISTS (SELECT 1 FROM trip_asset_assignments WHERE id=NEW.tractor_assignment_id AND trip_id=target AND asset_type='tractor_unit') THEN RAISE EXCEPTION 'Odometer requires assigned tractor history' USING ERRCODE='23514'; END IF;
                    IF NEW.previous_reading_id IS NULL THEN
                        IF NEW.revision<>1 THEN RAISE EXCEPTION 'Initial reading revision must be one' USING ERRCODE='23514'; END IF;
                    ELSE
                        SELECT * INTO original FROM trip_odometer_readings WHERE id=NEW.previous_reading_id FOR UPDATE;
                        IF original.id IS NULL OR original.trip_id<>NEW.trip_id OR original.tractor_assignment_id<>NEW.tractor_assignment_id OR original.reading_type<>NEW.reading_type OR NEW.revision<>original.revision+1 THEN
                            RAISE EXCEPTION 'Odometer correction identity mismatch' USING ERRCODE='23514';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            DROP TRIGGER transport_record ON trip_events;
            CREATE TRIGGER trip_operation_record BEFORE INSERT ON trip_events FOR EACH ROW EXECUTE FUNCTION trip_operation_record();
            CREATE TRIGGER trip_operation_lock BEFORE INSERT ON trip_events FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER trip_operation_lock BEFORE INSERT ON trip_loads FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER trip_operation_record BEFORE INSERT ON trip_loads FOR EACH ROW EXECUTE FUNCTION trip_operation_record();
            CREATE TRIGGER trip_operation_history BEFORE UPDATE OR DELETE ON trip_loads FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER trip_operation_no_truncate BEFORE TRUNCATE ON trip_loads FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER trip_operation_lock BEFORE INSERT ON load_items FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER trip_operation_record BEFORE INSERT ON load_items FOR EACH ROW EXECUTE FUNCTION trip_operation_record();
            CREATE TRIGGER trip_operation_history BEFORE UPDATE OR DELETE ON load_items FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER trip_operation_no_truncate BEFORE TRUNCATE ON load_items FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER trip_operation_lock BEFORE INSERT ON trip_unloads FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER trip_operation_record BEFORE INSERT ON trip_unloads FOR EACH ROW EXECUTE FUNCTION trip_operation_record();
            CREATE TRIGGER trip_operation_history BEFORE UPDATE OR DELETE ON trip_unloads FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER trip_operation_no_truncate BEFORE TRUNCATE ON trip_unloads FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER trip_operation_lock BEFORE INSERT ON unload_items FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER trip_operation_record BEFORE INSERT ON unload_items FOR EACH ROW EXECUTE FUNCTION trip_operation_record();
            CREATE TRIGGER trip_operation_history BEFORE UPDATE OR DELETE ON unload_items FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER trip_operation_no_truncate BEFORE TRUNCATE ON unload_items FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER trip_operation_lock BEFORE INSERT ON trip_odometer_readings FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER trip_operation_record BEFORE INSERT ON trip_odometer_readings FOR EACH ROW EXECUTE FUNCTION trip_operation_record();
            CREATE TRIGGER trip_operation_history BEFORE UPDATE OR DELETE ON trip_odometer_readings FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER trip_operation_no_truncate BEFORE TRUNCATE ON trip_odometer_readings FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Reversión operativa requiere revisión del historial; no borrarlo automáticamente.');
    }
};
