<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE driver_operation_receipts (
                id uuid PRIMARY KEY, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                request_key uuid NOT NULL, trip_id uuid NOT NULL REFERENCES trips(id) ON DELETE RESTRICT,
                kind varchar(20) NOT NULL CHECK (kind IN ('report','odometer')),
                fingerprint varchar(64) NOT NULL CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                record_id uuid NOT NULL, registered_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(actor_id,request_key), UNIQUE(kind,record_id)
            );
            CREATE INDEX driver_operation_trip_actor ON driver_operation_receipts(trip_id,actor_id,registered_at);
            CREATE FUNCTION driver_receipt_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM trip_operation_actor(NEW.actor_id,NEW.trip_id,true);
                IF NEW.kind='report' THEN
                    IF NOT EXISTS (SELECT 1 FROM trip_events WHERE id=NEW.record_id AND trip_id=NEW.trip_id AND created_by_id=NEW.actor_id) THEN
                        RAISE EXCEPTION 'Driver receipt report mismatch' USING ERRCODE='23514'; END IF;
                ELSE
                    IF NOT EXISTS (SELECT 1 FROM trip_odometer_readings WHERE id=NEW.record_id AND trip_id=NEW.trip_id AND recorded_by_id=NEW.actor_id AND previous_reading_id IS NULL) THEN
                        RAISE EXCEPTION 'Driver receipt odometer mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER driver_receipt_lock BEFORE INSERT ON driver_operation_receipts FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER driver_receipt_validate BEFORE INSERT ON driver_operation_receipts FOR EACH ROW EXECUTE FUNCTION driver_receipt_validate();
            CREATE TRIGGER driver_receipt_history BEFORE UPDATE OR DELETE ON driver_operation_receipts FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER driver_receipt_no_truncate BEFORE TRUNCATE ON driver_operation_receipts FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            DO $$ DECLARE definition text; BEGIN
                SELECT pg_get_functiondef('trip_operation_actor(bigint,uuid,boolean)'::regprocedure) INTO definition;
                IF position('a.trip_id=target' in definition)=0 THEN RAISE EXCEPTION 'Unexpected trip actor definition'; END IF;
                definition=replace(definition,'a.trip_id=target','a.trip_id=target AND a.starts_at<=clock_timestamp()');
                EXECUTE definition;
                SELECT pg_get_functiondef('trip_operation_record()'::regprocedure) INTO definition;
                IF position('PERFORM trip_operation_actor(NEW.recorded_by_id,target);' in definition)=0 THEN RAISE EXCEPTION 'Unexpected operation definition'; END IF;
                definition=replace(definition,'PERFORM trip_operation_actor(NEW.recorded_by_id,target);',
                    'IF TG_TABLE_NAME=''trip_odometer_readings'' THEN
                        PERFORM trip_operation_actor(NEW.recorded_by_id,target,NEW.previous_reading_id IS NULL);
                        IF NOT EXISTS (SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=NEW.recorded_by_id AND u.is_active AND r.name IN (''administrator'',''secretary'')) AND NOT EXISTS
                            (SELECT 1 FROM trip_asset_assignments a WHERE a.id=NEW.tractor_assignment_id AND a.trip_id=target AND a.asset_type=''tractor_unit'' AND a.starts_at<=clock_timestamp() AND NOT EXISTS (SELECT 1 FROM trip_asset_releases x WHERE x.assignment_id=a.id)) THEN
                            RAISE EXCEPTION ''Driver reading requires current tractor assignment'' USING ERRCODE=''23514''; END IF;
                    ELSE PERFORM trip_operation_actor(NEW.recorded_by_id,target); END IF;');
                EXECUTE definition;
            END $$;
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Driver operation audit migration requires an explicit audited rollback plan.');
    }
};
