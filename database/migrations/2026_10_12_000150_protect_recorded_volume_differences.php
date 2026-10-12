<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE transport_authorization_applications ADD COLUMN validation_type text NOT NULL DEFAULT 'existing',
                ADD CONSTRAINT trip_volume_application_kind CHECK (validation_type IN ('existing','volume_difference'));
            DROP TRIGGER transport_application ON transport_authorization_applications;
            CREATE TRIGGER transport_application BEFORE INSERT ON transport_authorization_applications FOR EACH ROW
                WHEN (NEW.change_id IS NOT NULL AND NEW.validation_type='existing') EXECUTE FUNCTION transport_validate_application();

            CREATE FUNCTION trip_volume_content(target uuid) RETURNS jsonb LANGUAGE plpgsql AS $$
            DECLARE load_id uuid; unload_id uuid; loaded numeric; delivered numeric; details jsonb; different boolean;
            BEGIN
                SELECT id INTO load_id FROM trip_loads WHERE trip_id=target;
                SELECT id INTO unload_id FROM trip_unloads WHERE trip_id=target;
                SELECT sum(loaded_liters) INTO loaded FROM load_items WHERE trip_load_id=load_id;
                SELECT sum(delivered_liters) INTO delivered FROM unload_items WHERE trip_unload_id=unload_id;
                SELECT jsonb_agg(jsonb_build_object('load_item_id',i.id,'compartment_id',i.compartment_id,
                    'loaded_liters',i.loaded_liters::text,'delivered_liters',u.delivered_liters::text) ORDER BY i.id),
                    bool_or(i.loaded_liters IS DISTINCT FROM u.delivered_liters)
                    INTO details,different FROM load_items i JOIN unload_items u ON u.load_item_id=i.id AND u.trip_unload_id=unload_id
                    WHERE i.trip_load_id=load_id;
                IF loaded IS NULL OR delivered IS NULL OR NOT coalesce(different,false) THEN RETURN NULL; END IF;
                RETURN jsonb_build_object('load_id',load_id,'unload_id',unload_id,'loaded_liters',loaded::text,'delivered_liters',delivered::text,'items',details);
            END; $$;

            CREATE FUNCTION trip_volume_application() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE intent transport_authorization_requests; change transport_changes; content jsonb;
            BEGIN
                PERFORM transport_assignment_actor(NEW.applied_by_id);
                SELECT q.* INTO intent FROM transport_authorization_requests q JOIN transport_authorization_decisions d ON d.authorization_request_id=q.id
                    WHERE d.id=NEW.decision_id AND d.decision='approved' FOR UPDATE OF q;
                SELECT * INTO change FROM transport_changes WHERE id=NEW.change_id;
                content=trip_volume_content(intent.trip_id);
                IF NEW.operation_id IS NOT NULL OR intent.id IS NULL OR intent.type<>'volume_difference' OR content IS NULL
                    OR intent.trip_id IS DISTINCT FROM change.trip_id OR intent.target_version IS DISTINCT FROM change.from_version
                    OR intent.content IS DISTINCT FROM content OR change.after_values->>'execution_status' IS DISTINCT FROM 'completed' THEN
                    RAISE EXCEPTION 'Volume approval does not cover recorded values and audited change' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER trip_volume_application BEFORE INSERT ON transport_authorization_applications FOR EACH ROW
                WHEN (NEW.validation_type='volume_difference') EXECUTE FUNCTION trip_volume_application();

            CREATE FUNCTION trip_volume_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE target uuid; expected_content jsonb; v_trip_version bigint; entering boolean DEFAULT false;
            BEGIN
                IF TG_TABLE_NAME='trips' THEN
                    target=NEW.id;
                    v_trip_version=NEW.version;
                    entering=(NEW.execution_status='completed' AND (TG_OP='INSERT' OR OLD.execution_status IS DISTINCT FROM 'completed'));
                ELSIF TG_TABLE_NAME='load_items' THEN SELECT trip_id INTO target FROM trip_loads WHERE id=NEW.trip_load_id;
                ELSE SELECT trip_id INTO target FROM trip_unloads WHERE id=NEW.trip_unload_id; END IF;
                IF (SELECT execution_status FROM trips WHERE id=target)<>'completed' THEN RETURN NULL; END IF;
                expected_content=trip_volume_content(target);
                IF expected_content IS NULL THEN RETURN NULL; END IF;
                IF NOT EXISTS (SELECT 1 FROM transport_authorization_applications a JOIN transport_authorization_decisions d ON d.id=a.decision_id
                    JOIN transport_authorization_requests q ON q.id=d.authorization_request_id JOIN transport_changes c ON c.id=a.change_id
                    WHERE a.validation_type='volume_difference' AND d.decision='approved' AND q.type='volume_difference'
                    AND q.trip_id=target AND c.trip_id=target AND q.target_version=c.from_version AND q.content=expected_content
                    AND c.after_values->>'execution_status'='completed' AND (NOT entering OR c.to_version=v_trip_version)) THEN
                    RAISE EXCEPTION 'Recorded volume difference requires consumed administrator approval' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER trip_recorded_difference AFTER INSERT OR UPDATE ON trips DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION trip_volume_integrity();
            CREATE CONSTRAINT TRIGGER trip_recorded_difference AFTER INSERT ON load_items DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION trip_volume_integrity();
            CREATE CONSTRAINT TRIGGER trip_recorded_difference AFTER INSERT ON unload_items DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION trip_volume_integrity();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Reversión requiere preservar aprobaciones y revisar el historial operativo.');
    }
};
