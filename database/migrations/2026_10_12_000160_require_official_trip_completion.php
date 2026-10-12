<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION trip_require_completion(target uuid) RETURNS void LANGUAGE plpgsql AS $$
            DECLARE journey trips; loading uuid; unloading uuid;
            BEGIN
                SELECT * INTO journey FROM trips WHERE id=target FOR UPDATE;
                SELECT id INTO loading FROM trip_loads WHERE trip_id=target;
                SELECT id INTO unloading FROM trip_unloads WHERE trip_id=target AND trip_load_id=loading;
                IF journey.officially_started_at IS NULL OR journey.started_by_id IS NULL
                    OR NOT EXISTS (SELECT 1 FROM driver_assignments WHERE trip_id=target)
                    OR NOT EXISTS (SELECT 1 FROM trip_asset_assignments WHERE trip_id=target AND asset_type='tractor_unit')
                    OR NOT EXISTS (SELECT 1 FROM trip_asset_assignments WHERE trip_id=target AND asset_type='tanker')
                    OR loading IS NULL OR unloading IS NULL
                    OR NOT EXISTS (SELECT 1 FROM load_items WHERE trip_load_id=loading)
                    OR EXISTS (SELECT 1 FROM load_items i WHERE i.trip_load_id=loading AND NOT EXISTS
                        (SELECT 1 FROM unload_items u WHERE u.load_item_id=i.id AND u.trip_unload_id=unloading)) THEN
                    RAISE EXCEPTION 'Official completion requires start, assignment history and full load/unload coverage' USING ERRCODE='23514';
                END IF;
            END; $$;
            CREATE FUNCTION trip_completion_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE target uuid;
            BEGIN
                IF TG_TABLE_NAME='trips' THEN
                    IF NEW.execution_status<>'completed' OR (TG_OP='UPDATE' AND OLD.execution_status='completed') THEN RETURN NULL; END IF;
                    target=NEW.id;
                    PERFORM trip_operation_actor(NEW.completed_by_id,target,false);
                ELSIF TG_TABLE_NAME='load_items' THEN SELECT trip_id INTO target FROM trip_loads WHERE id=NEW.trip_load_id;
                ELSE SELECT trip_id INTO target FROM trip_unloads WHERE id=NEW.trip_unload_id; END IF;
                IF (SELECT execution_status FROM trips WHERE id=target)='completed' THEN PERFORM trip_require_completion(target); END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER trip_completion_requirements AFTER INSERT OR UPDATE ON trips DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION trip_completion_integrity();
            CREATE CONSTRAINT TRIGGER trip_completion_requirements AFTER INSERT ON load_items DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION trip_completion_integrity();
            CREATE CONSTRAINT TRIGGER trip_completion_requirements AFTER INSERT ON unload_items DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION trip_completion_integrity();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Reversión requiere revisión del historial de finalizaciones oficiales.');
    }
};
