<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE transport_authorization_requests DROP CONSTRAINT transport_auth_type, DROP CONSTRAINT transport_auth_type_target,
                ADD CONSTRAINT transport_auth_type CHECK (type IN ('product_change','volume_difference','price_change','expense_without_receipt','allowance_disbursement','cancellation_charge','assignment_conflict','driver_substitution','tractor_substitution','tanker_substitution')),
                ADD CONSTRAINT transport_auth_type_target CHECK ((type='product_change' AND transport_request_id IS NOT NULL) OR (type<>'product_change' AND trip_id IS NOT NULL));
            ALTER TABLE transport_authorization_applications ALTER COLUMN change_id DROP NOT NULL,
                ADD COLUMN operation_id uuid REFERENCES transport_assignment_operations(id) ON DELETE RESTRICT,
                ADD CONSTRAINT transport_application_target CHECK (num_nonnulls(change_id,operation_id)=1);
            CREATE INDEX transport_application_operation ON transport_authorization_applications(operation_id);
            DROP TRIGGER transport_application ON transport_authorization_applications;
            CREATE TRIGGER transport_application BEFORE INSERT ON transport_authorization_applications FOR EACH ROW
                WHEN (NEW.change_id IS NOT NULL) EXECUTE FUNCTION transport_validate_application();
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION transport_assignment_lock() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(873210);
                UPDATE transport_assignment_guard SET version=version+1 WHERE id=1;
                RETURN NULL;
            END; $$;

            CREATE FUNCTION transport_assignment_actor(actor bigint) RETURNS void LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(873210);
                PERFORM 1 FROM users WHERE id=actor FOR UPDATE;
                PERFORM 1 FROM user_roles WHERE user_id=actor FOR UPDATE;
                PERFORM transport_require_role(actor);
            END; $$;

            CREATE FUNCTION transport_assignment_record() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE operation transport_assignment_operations; target trips; eligible boolean; conflict_ids jsonb;
                previous_id uuid; outgoing_start timestamptz;
            BEGIN
                IF TG_TABLE_NAME='transport_assignment_operations' THEN
                    PERFORM transport_assignment_actor(NEW.actor_id);
                    SELECT * INTO target FROM trips WHERE id=NEW.trip_id FOR UPDATE;
                    NEW.execution_status_snapshot=target.execution_status;
                    NEW.registered_at=clock_timestamp();
                    IF NEW.payload->>'resource_kind' IS DISTINCT FROM NEW.resource_kind
                        OR (NEW.action<>'conflict_detected' AND NEW.payload->>'action' IS DISTINCT FROM NEW.action) THEN
                        RAISE EXCEPTION 'Operation payload identity mismatch' USING ERRCODE='23514';
                    END IF;
                    IF jsonb_typeof(NEW.payload->'conflicts') IS DISTINCT FROM 'array'
                        OR (NEW.action='assign' AND NEW.payload->>'previous_assignment_id' IS NOT NULL)
                        OR (NEW.action IN ('release','substitute') AND NEW.payload->>'previous_assignment_id' IS NULL) THEN
                        RAISE EXCEPTION 'Malformed assignment intent' USING ERRCODE='23514';
                    END IF;
                    IF target.version IS DISTINCT FROM NEW.target_version THEN RAISE EXCEPTION 'Stale assignment operation' USING ERRCODE='23514'; END IF;
                    RETURN NEW;
                END IF;
                SELECT * INTO operation FROM transport_assignment_operations WHERE id=NEW.operation_id;
                PERFORM transport_assignment_actor(operation.actor_id);
                SELECT * INTO target FROM trips WHERE id=operation.trip_id FOR UPDATE;
                IF TG_TABLE_NAME IN ('trip_asset_assignments','driver_assignments') THEN
                    IF operation.action NOT IN ('assign','substitute') OR NEW.trip_id<>operation.trip_id OR operation.payload->>'resource_id' IS NULL THEN
                        RAISE EXCEPTION 'Invalid assignment operation' USING ERRCODE='23514';
                    END IF;
                    IF TG_TABLE_NAME='driver_assignments' THEN
                        IF operation.resource_kind<>'driver' OR NEW.employee_id::text IS DISTINCT FROM operation.payload->>'resource_id' THEN
                            RAISE EXCEPTION 'Driver identity mismatch' USING ERRCODE='23514';
                        END IF;
                        PERFORM 1 FROM employees WHERE id=NEW.employee_id FOR UPDATE;
                        PERFORM 1 FROM users WHERE id=(SELECT user_id FROM employees WHERE id=NEW.employee_id) FOR UPDATE;
                        PERFORM 1 FROM user_roles WHERE user_id=(SELECT user_id FROM employees WHERE id=NEW.employee_id) FOR UPDATE;
                        SELECT EXISTS (SELECT 1 FROM employees e JOIN users u ON u.id=e.user_id JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
                            WHERE e.id=NEW.employee_id AND e.is_active AND u.is_active AND r.name='driver') INTO eligible;
                        SELECT COALESCE(jsonb_agg(a.id ORDER BY a.id),'[]'::jsonb) INTO conflict_ids FROM driver_assignments a
                            WHERE a.employee_id=NEW.employee_id AND NOT EXISTS (SELECT 1 FROM driver_releases x WHERE x.assignment_id=a.id);
                    ELSE
                        IF operation.resource_kind<>NEW.asset_type OR NEW.fleet_asset_id::text IS DISTINCT FROM operation.payload->>'resource_id' THEN
                            RAISE EXCEPTION 'Asset identity mismatch' USING ERRCODE='23514';
                        END IF;
                        PERFORM 1 FROM fleet_assets WHERE id=NEW.fleet_asset_id FOR UPDATE;
                        SELECT EXISTS (SELECT 1 FROM fleet_assets WHERE id=NEW.fleet_asset_id AND type=NEW.asset_type AND service_status='available') INTO eligible;
                        SELECT COALESCE(jsonb_agg(a.id ORDER BY a.id),'[]'::jsonb) INTO conflict_ids FROM trip_asset_assignments a
                            WHERE a.fleet_asset_id=NEW.fleet_asset_id AND NOT EXISTS (SELECT 1 FROM trip_asset_releases x WHERE x.assignment_id=a.id);
                    END IF;
                    IF NEW.starts_at<operation.registered_at OR NEW.starts_at>clock_timestamp() THEN RAISE EXCEPTION 'Assignment time must be actual server time' USING ERRCODE='23514'; END IF;
                    IF NOT eligible THEN RAISE EXCEPTION 'Resource is not eligible' USING ERRCODE='23514'; END IF;
                    IF conflict_ids IS DISTINCT FROM operation.payload->'conflicts' THEN RAISE EXCEPTION 'Conflict set changed' USING ERRCODE='23514'; END IF;
                    IF operation.execution_status_snapshot='in_progress' AND operation.action='assign' THEN
                        IF TG_TABLE_NAME='driver_assignments' THEN
                            IF EXISTS (SELECT 1 FROM driver_assignments WHERE trip_id=NEW.trip_id) THEN
                                RAISE EXCEPTION 'Active substitution requires explicit operation' USING ERRCODE='23514';
                            END IF;
                        ELSE
                            IF EXISTS (SELECT 1 FROM trip_asset_assignments WHERE trip_id=NEW.trip_id AND asset_type=NEW.asset_type) THEN
                                RAISE EXCEPTION 'Active substitution requires explicit operation' USING ERRCODE='23514';
                            END IF;
                        END IF;
                    END IF;
                ELSIF TG_TABLE_NAME IN ('trip_asset_releases','driver_releases') THEN
                    IF operation.action NOT IN ('release','substitute') OR NEW.assignment_id::text IS DISTINCT FROM operation.payload->>'previous_assignment_id' THEN
                        RAISE EXCEPTION 'Invalid release identity' USING ERRCODE='23514';
                    END IF;
                    IF TG_TABLE_NAME='driver_releases' THEN
                        IF operation.resource_kind<>'driver' THEN RAISE EXCEPTION 'Release kind mismatch' USING ERRCODE='23514'; END IF;
                        SELECT starts_at INTO outgoing_start FROM driver_assignments WHERE id=NEW.assignment_id AND trip_id=operation.trip_id;
                    ELSE
                        SELECT starts_at INTO outgoing_start FROM trip_asset_assignments WHERE id=NEW.assignment_id AND trip_id=operation.trip_id AND asset_type=operation.resource_kind;
                    END IF;
                    IF outgoing_start IS NULL OR NEW.ends_at<outgoing_start OR NEW.ends_at<operation.registered_at OR NEW.ends_at>clock_timestamp() THEN RAISE EXCEPTION 'Invalid release interval' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;

            CREATE FUNCTION transport_assignment_decision() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE requested_type text;
            BEGIN
                SELECT type INTO requested_type FROM transport_authorization_requests WHERE id=NEW.authorization_request_id;
                IF requested_type IN ('assignment_conflict','driver_substitution','tractor_substitution','tanker_substitution') THEN
                    PERFORM transport_assignment_actor(NEW.decided_by_id);
                    PERFORM transport_require_role(NEW.decided_by_id,true);
                END IF;
                RETURN NEW;
            END; $$;

            CREATE FUNCTION transport_assignment_application() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE operation transport_assignment_operations; intent transport_authorization_requests;
            BEGIN
                PERFORM transport_assignment_actor(NEW.applied_by_id);
                SELECT q.* INTO intent FROM transport_authorization_requests q JOIN transport_authorization_decisions d ON d.authorization_request_id=q.id
                    WHERE d.id=NEW.decision_id AND d.decision='approved' FOR UPDATE OF q;
                SELECT * INTO operation FROM transport_assignment_operations WHERE id=NEW.operation_id;
                IF intent.id IS NULL OR operation.action NOT IN ('assign','substitute') OR intent.trip_id IS DISTINCT FROM operation.trip_id
                    OR intent.target_version IS DISTINCT FROM operation.target_version OR intent.content IS DISTINCT FROM operation.payload
                    OR NEW.applied_by_id<>operation.actor_id
                    OR intent.type NOT IN ('assignment_conflict','driver_substitution','tractor_substitution','tanker_substitution') THEN
                    RAISE EXCEPTION 'Approval does not cover assignment operation' USING ERRCODE='23514';
                END IF;
                IF intent.type<>'assignment_conflict' AND (operation.action<>'substitute' OR intent.type IS DISTINCT FROM
                    CASE operation.resource_kind WHEN 'driver' THEN 'driver_substitution' WHEN 'tractor_unit' THEN 'tractor_substitution' ELSE 'tanker_substitution' END) THEN
                    RAISE EXCEPTION 'Substitution authorization type mismatch' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;

            CREATE FUNCTION transport_check_trip_assignments(target_id uuid) RETURNS void LANGUAGE plpgsql AS $$
            DECLARE state text; drivers integer; tractors integer; tankers integer;
            BEGIN
                SELECT execution_status INTO state FROM trips WHERE id=target_id;
                SELECT count(*) INTO drivers FROM driver_assignments a WHERE a.trip_id=target_id AND NOT EXISTS (SELECT 1 FROM driver_releases r WHERE r.assignment_id=a.id);
                SELECT count(*) FILTER (WHERE a.asset_type='tractor_unit'),count(*) FILTER (WHERE a.asset_type='tanker') INTO tractors,tankers
                    FROM trip_asset_assignments a WHERE a.trip_id=target_id AND NOT EXISTS (SELECT 1 FROM trip_asset_releases r WHERE r.assignment_id=a.id);
                IF drivers>1 OR tractors>1 OR tankers>1 OR (state='in_progress' AND (drivers<>1 OR tractors<>1 OR tankers<>1)) THEN
                    RAISE EXCEPTION 'Active trip must have exactly one resource of each kind' USING ERRCODE='23514';
                END IF;
            END; $$;

            CREATE FUNCTION transport_assignment_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE operation transport_assignment_operations; assignments integer; releases integer; records jsonb; expected_type text;
            BEGIN
                IF TG_TABLE_NAME='trips' THEN
                    PERFORM transport_check_trip_assignments(NEW.id);
                    RETURN NULL;
                END IF;
                SELECT * INTO operation FROM transport_assignment_operations WHERE id=NEW.id;
                SELECT (SELECT count(*) FROM driver_assignments WHERE operation_id=operation.id)+(SELECT count(*) FROM trip_asset_assignments WHERE operation_id=operation.id) INTO assignments;
                SELECT (SELECT count(*) FROM driver_releases WHERE operation_id=operation.id)+(SELECT count(*) FROM trip_asset_releases WHERE operation_id=operation.id) INTO releases;
                IF (operation.action='assign' AND (assignments<>1 OR releases<>0)) OR (operation.action='release' AND (assignments<>0 OR releases<>1))
                    OR (operation.action='substitute' AND (assignments<>1 OR releases<>1)) OR (operation.action='conflict_detected' AND (assignments<>0 OR releases<>0)) THEN
                    RAISE EXCEPTION 'Incomplete assignment operation' USING ERRCODE='23514';
                END IF;
                IF operation.action IN ('assign','substitute','conflict_detected') THEN
                    SELECT COALESCE(jsonb_agg(COALESCE(asset_assignment_id,driver_assignment_id) ORDER BY COALESCE(asset_assignment_id,driver_assignment_id)),'[]'::jsonb) INTO records
                        FROM transport_assignment_conflicts WHERE operation_id=operation.id;
                    IF records IS DISTINCT FROM operation.payload->'conflicts' THEN RAISE EXCEPTION 'Conflict history incomplete' USING ERRCODE='23514'; END IF;
                    IF operation.action<>'conflict_detected' AND jsonb_array_length(records)>0 AND NOT EXISTS
                        (SELECT 1 FROM transport_authorization_applications a JOIN transport_authorization_decisions d ON d.id=a.decision_id JOIN transport_authorization_requests q ON q.id=d.authorization_request_id
                            WHERE a.operation_id=operation.id AND q.type='assignment_conflict') THEN
                        RAISE EXCEPTION 'Conflicts require administrator approval' USING ERRCODE='23514';
                    END IF;
                END IF;
                IF operation.action='substitute' AND operation.resource_kind='tanker' THEN RAISE EXCEPTION 'Empty tanker verification procedure pending' USING ERRCODE='23514'; END IF;
                IF operation.action='substitute' AND operation.execution_status_snapshot='in_progress' THEN
                    expected_type=CASE WHEN operation.resource_kind='driver' THEN 'driver_substitution' ELSE 'tractor_substitution' END;
                    IF NOT EXISTS (SELECT 1 FROM transport_authorization_applications a JOIN transport_authorization_decisions d ON d.id=a.decision_id JOIN transport_authorization_requests q ON q.id=d.authorization_request_id
                        WHERE a.operation_id=operation.id AND q.type=expected_type) THEN RAISE EXCEPTION 'Substitution requires administrator approval' USING ERRCODE='23514'; END IF;
                END IF;
                PERFORM transport_check_trip_assignments(operation.trip_id);
                RETURN NULL;
            END; $$;

            CREATE FUNCTION transport_assignment_conflict_record() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE operation transport_assignment_operations; other_trip uuid; resource_id uuid;
            BEGIN
                SELECT * INTO operation FROM transport_assignment_operations WHERE id=NEW.operation_id;
                IF NEW.asset_assignment_id IS NOT NULL THEN
                    SELECT trip_id,fleet_asset_id INTO other_trip,resource_id FROM trip_asset_assignments WHERE id=NEW.asset_assignment_id AND asset_type=operation.resource_kind;
                ELSE
                    SELECT trip_id,employee_id INTO other_trip,resource_id FROM driver_assignments WHERE id=NEW.driver_assignment_id AND operation.resource_kind='driver';
                END IF;
                IF other_trip IS NULL OR other_trip=operation.trip_id OR resource_id::text IS DISTINCT FROM operation.payload->>'resource_id' THEN
                    RAISE EXCEPTION 'Conflict identity mismatch' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER assignment_lock BEFORE INSERT ON transport_assignment_operations FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER assignment_history BEFORE UPDATE OR DELETE ON transport_assignment_operations FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_no_truncate BEFORE TRUNCATE ON transport_assignment_operations FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_record BEFORE INSERT ON transport_assignment_operations FOR EACH ROW EXECUTE FUNCTION transport_assignment_record();
            CREATE TRIGGER assignment_lock BEFORE INSERT ON trip_asset_assignments FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER assignment_history BEFORE UPDATE OR DELETE ON trip_asset_assignments FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_no_truncate BEFORE TRUNCATE ON trip_asset_assignments FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_record BEFORE INSERT ON trip_asset_assignments FOR EACH ROW EXECUTE FUNCTION transport_assignment_record();
            CREATE TRIGGER assignment_lock BEFORE INSERT ON trip_asset_releases FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER assignment_history BEFORE UPDATE OR DELETE ON trip_asset_releases FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_no_truncate BEFORE TRUNCATE ON trip_asset_releases FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_record BEFORE INSERT ON trip_asset_releases FOR EACH ROW EXECUTE FUNCTION transport_assignment_record();
            CREATE TRIGGER assignment_lock BEFORE INSERT ON driver_assignments FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER assignment_history BEFORE UPDATE OR DELETE ON driver_assignments FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_no_truncate BEFORE TRUNCATE ON driver_assignments FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_record BEFORE INSERT ON driver_assignments FOR EACH ROW EXECUTE FUNCTION transport_assignment_record();
            CREATE TRIGGER assignment_lock BEFORE INSERT ON driver_releases FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER assignment_history BEFORE UPDATE OR DELETE ON driver_releases FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_no_truncate BEFORE TRUNCATE ON driver_releases FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_record BEFORE INSERT ON driver_releases FOR EACH ROW EXECUTE FUNCTION transport_assignment_record();
            CREATE TRIGGER assignment_lock BEFORE INSERT ON transport_assignment_conflicts FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER assignment_history BEFORE UPDATE OR DELETE ON transport_assignment_conflicts FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_no_truncate BEFORE TRUNCATE ON transport_assignment_conflicts FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_conflict_record BEFORE INSERT ON transport_assignment_conflicts FOR EACH ROW EXECUTE FUNCTION transport_assignment_conflict_record();
            CREATE TRIGGER assignment_decision BEFORE INSERT ON transport_authorization_decisions FOR EACH ROW EXECUTE FUNCTION transport_assignment_decision();
            CREATE TRIGGER assignment_application BEFORE INSERT ON transport_authorization_applications FOR EACH ROW WHEN (NEW.operation_id IS NOT NULL) EXECUTE FUNCTION transport_assignment_application();
            CREATE CONSTRAINT TRIGGER assignment_operation_integrity AFTER INSERT ON transport_assignment_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION transport_assignment_integrity();
            CREATE CONSTRAINT TRIGGER assignment_trip_integrity AFTER INSERT OR UPDATE ON trips DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION transport_assignment_integrity();
            CREATE TRIGGER assignment_trip_lock BEFORE INSERT OR UPDATE ON trips FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock();
            CREATE TRIGGER assignment_guard_no_delete BEFORE DELETE ON transport_assignment_guard FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER assignment_guard_no_truncate BEFORE TRUNCATE ON transport_assignment_guard FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Reversión de asignaciones requiere revisión y plan de recuperación; no borrar historial.');
    }
};
