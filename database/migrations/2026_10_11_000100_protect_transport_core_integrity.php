<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_authorization_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('decision_id')->constrained('transport_authorization_decisions')->restrictOnDelete();
            $table->unique('decision_id');
            $table->foreignUuid('change_id')->constrained('transport_changes')->restrictOnDelete();
            $table->unique('change_id');
            $table->foreignId('applied_by_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('registered_at')->useCurrent();
        });
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION transport_preserve_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Transport history is immutable' USING ERRCODE='23514';
            END; $$;

            CREATE FUNCTION transport_require_role(actor bigint, administrator_only boolean DEFAULT false) RETURNS void LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(873210);
                IF NOT EXISTS (SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
                    WHERE u.id=actor AND u.is_active AND (r.name='administrator' OR (NOT administrator_only AND r.name='secretary'))) THEN
                    RAISE EXCEPTION 'Transport actor not authorized' USING ERRCODE='23514';
                END IF;
            END; $$;

            CREATE FUNCTION transport_validate_record() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE intent transport_authorization_requests; change transport_changes; review_trip trips;
                warning trip_warnings; current_version bigint; current_row jsonb;
            BEGIN
                IF TG_TABLE_NAME IN ('transport_requests','trips') THEN
                    PERFORM transport_require_role(NEW.registered_by_id);
                    IF NEW.version<>1 THEN RAISE EXCEPTION 'Initial version must be one' USING ERRCODE='23514'; END IF;
                    IF TG_TABLE_NAME='trips' THEN
                        IF NEW.started_by_id IS NOT NULL THEN PERFORM transport_require_role(NEW.started_by_id); END IF;
                        IF NEW.completed_by_id IS NOT NULL THEN PERFORM transport_require_role(NEW.completed_by_id); END IF;
                        IF NEW.closed_by_id IS NOT NULL THEN PERFORM transport_require_role(NEW.closed_by_id); END IF;
                        IF NEW.cancelled_by_id IS NOT NULL THEN PERFORM transport_require_role(NEW.cancelled_by_id); END IF;
                        PERFORM 1 FROM transport_requests WHERE id=NEW.transport_request_id FOR UPDATE;
                        IF NOT EXISTS (SELECT 1 FROM transport_requests q JOIN liquid_products p ON p.id=q.liquid_product_id
                            WHERE q.id=NEW.transport_request_id AND q.liquid_product_id=NEW.liquid_product_id AND p.name=NEW.product_name_snapshot) THEN
                            RAISE EXCEPTION 'Initial product must match request' USING ERRCODE='23514';
                        END IF;
                    END IF;
                ELSIF TG_TABLE_NAME='transport_authorization_requests' THEN
                    PERFORM transport_require_role(NEW.requested_by_id);
                    IF NEW.transport_request_id IS NOT NULL THEN SELECT version INTO current_version FROM transport_requests WHERE id=NEW.transport_request_id FOR UPDATE;
                    ELSE SELECT version INTO current_version FROM trips WHERE id=NEW.trip_id FOR UPDATE; END IF;
                    IF current_version IS DISTINCT FROM NEW.target_version THEN RAISE EXCEPTION 'Stale authorization intent' USING ERRCODE='23514'; END IF;
                ELSIF TG_TABLE_NAME='transport_authorization_decisions' THEN
                    PERFORM transport_require_role(NEW.decided_by_id,true);
                ELSIF TG_TABLE_NAME='transport_changes' THEN
                    PERFORM transport_require_role(NEW.actor_id);
                    IF NEW.transport_request_id IS NOT NULL THEN SELECT to_jsonb(q) INTO current_row FROM transport_requests q WHERE id=NEW.transport_request_id;
                    ELSE SELECT to_jsonb(t) INTO current_row FROM trips t WHERE id=NEW.trip_id; END IF;
                    IF current_row IS DISTINCT FROM NEW.after_values OR (current_row->>'version')::bigint IS DISTINCT FROM NEW.to_version THEN
                        RAISE EXCEPTION 'Audit must describe current target' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='trip_product_reviews' THEN
                    PERFORM transport_require_role(NEW.reviewed_by_id);
                    SELECT * INTO change FROM transport_changes WHERE id=NEW.request_change_id;
                    SELECT * INTO review_trip FROM trips WHERE id=NEW.trip_id FOR UPDATE;
                    IF change.transport_request_id IS DISTINCT FROM review_trip.transport_request_id
                        OR change.before_values->>'liquid_product_id' IS DISTINCT FROM NEW.previous_product_id::text
                        OR change.after_values->>'liquid_product_id' IS DISTINCT FROM NEW.proposed_product_id::text
                        OR NEW.previous_product_id=NEW.proposed_product_id THEN
                        RAISE EXCEPTION 'Product review identity mismatch' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='trip_events' THEN
                    -- Driver ownership depends on assignments in 5.2B; no unrestricted driver write.
                    PERFORM transport_require_role(NEW.created_by_id);
                ELSIF TG_TABLE_NAME='trip_warnings' THEN
                    IF NOT EXISTS (SELECT 1 FROM trips WHERE id=NEW.trip_id AND administrative_status='closed' AND version=NEW.closure_version) THEN
                        RAISE EXCEPTION 'Warning must identify actual closure version' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='trip_warning_resolutions' THEN
                    PERFORM transport_require_role(NEW.actor_id);
                END IF;
                RETURN NEW;
            END; $$;

            CREATE FUNCTION transport_validate_application() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE intent transport_authorization_requests; change transport_changes;
            BEGIN
                PERFORM transport_require_role(NEW.applied_by_id);
                SELECT q.* INTO intent FROM transport_authorization_requests q JOIN transport_authorization_decisions d ON d.authorization_request_id=q.id
                    WHERE d.id=NEW.decision_id AND d.decision='approved' FOR UPDATE OF q;
                SELECT * INTO change FROM transport_changes WHERE id=NEW.change_id;
                IF intent.id IS NULL OR intent.transport_request_id IS DISTINCT FROM change.transport_request_id OR intent.trip_id IS DISTINCT FROM change.trip_id
                    OR intent.target_version IS DISTINCT FROM change.from_version OR intent.type<>'product_change'
                    OR jsonb_typeof(intent.content->'before') IS DISTINCT FROM 'object' OR jsonb_typeof(intent.content->'after') IS DISTINCT FROM 'object'
                    OR NOT (intent.content->'before' <@ change.before_values) OR NOT (intent.content->'after' <@ change.after_values)
                    OR intent.content->'before'->>'liquid_product_id' IS DISTINCT FROM change.before_values->>'liquid_product_id'
                    OR intent.content->'after'->>'liquid_product_id' IS DISTINCT FROM change.after_values->>'liquid_product_id'
                    OR change.before_values->>'liquid_product_id'=change.after_values->>'liquid_product_id' THEN
                    RAISE EXCEPTION 'Authorization cannot cover this application' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;

            CREATE FUNCTION transport_lock_write() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Statement lock precedes row locks and shares revocation coordination.
                PERFORM pg_advisory_xact_lock(873210);
                RETURN NULL;
            END; $$;

            CREATE FUNCTION transport_validate_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id<>OLD.id OR NEW.registered_at<>OLD.registered_at OR NEW.registered_by_id<>OLD.registered_by_id OR NEW.version<>OLD.version+1 THEN
                    RAISE EXCEPTION 'Invalid identity or version update' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME='trips' THEN
                    IF NEW.transport_request_id<>OLD.transport_request_id OR NEW.liquid_product_id<>OLD.liquid_product_id OR NEW.product_name_snapshot<>OLD.product_name_snapshot THEN
                        RAISE EXCEPTION 'Historical trip product is immutable in 5.2A' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;

            CREATE FUNCTION transport_require_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE audit_change_id uuid; actor bigint;
            BEGIN
                SELECT id,actor_id INTO audit_change_id,actor FROM transport_changes c WHERE c.from_version=OLD.version AND c.to_version=NEW.version
                    AND ((TG_TABLE_NAME='trips' AND c.trip_id=NEW.id) OR (TG_TABLE_NAME='transport_requests' AND c.transport_request_id=NEW.id))
                    AND c.before_values=to_jsonb(OLD) AND c.after_values=to_jsonb(NEW);
                IF audit_change_id IS NULL THEN RAISE EXCEPTION 'Exact audit required in same transaction' USING ERRCODE='23514'; END IF;
                PERFORM transport_require_role(actor);
                IF TG_TABLE_NAME='trips' THEN
                    IF NEW.started_by_id IS DISTINCT FROM OLD.started_by_id AND NEW.started_by_id IS NOT NULL THEN PERFORM transport_require_role(NEW.started_by_id); END IF;
                    IF NEW.completed_by_id IS DISTINCT FROM OLD.completed_by_id AND NEW.completed_by_id IS NOT NULL THEN PERFORM transport_require_role(NEW.completed_by_id); END IF;
                    IF NEW.closed_by_id IS DISTINCT FROM OLD.closed_by_id AND NEW.closed_by_id IS NOT NULL THEN PERFORM transport_require_role(NEW.closed_by_id); END IF;
                    IF NEW.cancelled_by_id IS DISTINCT FROM OLD.cancelled_by_id AND NEW.cancelled_by_id IS NOT NULL THEN PERFORM transport_require_role(NEW.cancelled_by_id); END IF;
                ELSIF NEW.liquid_product_id<>OLD.liquid_product_id THEN
                    IF NOT EXISTS (SELECT 1 FROM transport_authorization_applications a WHERE a.change_id=audit_change_id) THEN
                        RAISE EXCEPTION 'Product change needs matching approval' USING ERRCODE='23514';
                    END IF;
                    IF EXISTS (SELECT 1 FROM trips t WHERE t.transport_request_id=NEW.id AND NOT EXISTS
                        (SELECT 1 FROM trip_product_reviews r WHERE r.trip_id=t.id AND r.request_change_id=audit_change_id)) THEN
                        RAISE EXCEPTION 'Affected trips require review' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NULL;
            END; $$;

            CREATE FUNCTION transport_preserve_used_route() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM transport_requests WHERE route_id=OLD.id) THEN RAISE EXCEPTION 'Referenced route is immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER transport_history BEFORE UPDATE OR DELETE ON transport_authorization_requests FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON transport_authorization_requests FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_history BEFORE UPDATE OR DELETE ON transport_authorization_decisions FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON transport_authorization_decisions FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_history BEFORE UPDATE OR DELETE ON transport_authorization_applications FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON transport_authorization_applications FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_history BEFORE UPDATE OR DELETE ON transport_changes FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON transport_changes FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_history BEFORE UPDATE OR DELETE ON trip_product_reviews FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON trip_product_reviews FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_history BEFORE UPDATE OR DELETE ON trip_events FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON trip_events FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_history BEFORE UPDATE OR DELETE ON trip_warnings FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON trip_warnings FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_history BEFORE UPDATE OR DELETE ON trip_warning_resolutions FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON trip_warning_resolutions FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_record BEFORE INSERT ON transport_requests FOR EACH ROW EXECUTE FUNCTION transport_validate_record();
            CREATE TRIGGER transport_version BEFORE UPDATE ON transport_requests FOR EACH ROW EXECUTE FUNCTION transport_validate_update();
            CREATE CONSTRAINT TRIGGER transport_audit AFTER UPDATE ON transport_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION transport_require_audit();
            CREATE TRIGGER transport_no_delete BEFORE DELETE ON transport_requests FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON transport_requests FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_record BEFORE INSERT ON trips FOR EACH ROW EXECUTE FUNCTION transport_validate_record();
            CREATE TRIGGER transport_version BEFORE UPDATE ON trips FOR EACH ROW EXECUTE FUNCTION transport_validate_update();
            CREATE CONSTRAINT TRIGGER transport_audit AFTER UPDATE ON trips DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION transport_require_audit();
            CREATE TRIGGER transport_no_delete BEFORE DELETE ON trips FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON trips FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_record BEFORE INSERT ON transport_authorization_requests FOR EACH ROW EXECUTE FUNCTION transport_validate_record();
            CREATE TRIGGER transport_record BEFORE INSERT ON transport_authorization_decisions FOR EACH ROW EXECUTE FUNCTION transport_validate_record();
            CREATE TRIGGER transport_record BEFORE INSERT ON transport_changes FOR EACH ROW EXECUTE FUNCTION transport_validate_record();
            CREATE TRIGGER transport_record BEFORE INSERT ON trip_product_reviews FOR EACH ROW EXECUTE FUNCTION transport_validate_record();
            CREATE TRIGGER transport_record BEFORE INSERT ON trip_events FOR EACH ROW EXECUTE FUNCTION transport_validate_record();
            CREATE TRIGGER transport_record BEFORE INSERT ON trip_warnings FOR EACH ROW EXECUTE FUNCTION transport_validate_record();
            CREATE TRIGGER transport_record BEFORE INSERT ON trip_warning_resolutions FOR EACH ROW EXECUTE FUNCTION transport_validate_record();
            CREATE TRIGGER transport_application BEFORE INSERT ON transport_authorization_applications FOR EACH ROW EXECUTE FUNCTION transport_validate_application();
            CREATE TRIGGER transport_write_lock BEFORE UPDATE ON transport_requests FOR EACH STATEMENT EXECUTE FUNCTION transport_lock_write();
            CREATE TRIGGER transport_write_lock BEFORE UPDATE ON trips FOR EACH STATEMENT EXECUTE FUNCTION transport_lock_write();
            CREATE TRIGGER transport_write_lock BEFORE UPDATE OR DELETE ON routes FOR EACH STATEMENT EXECUTE FUNCTION transport_lock_write();
            CREATE TRIGGER transport_route_history BEFORE UPDATE OR DELETE ON routes FOR EACH ROW EXECUTE FUNCTION transport_preserve_used_route();
            CREATE TRIGGER transport_no_truncate BEFORE TRUNCATE ON routes FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER transport_write_lock ON transport_requests;
            DROP TRIGGER transport_write_lock ON trips;
            DROP TRIGGER transport_write_lock ON routes;
            DROP FUNCTION transport_lock_write();
            DROP TRIGGER IF EXISTS transport_history ON transport_authorization_requests;
            DROP TRIGGER IF EXISTS transport_no_truncate ON transport_authorization_requests;
            DROP TRIGGER IF EXISTS transport_record ON transport_authorization_requests;
            DROP TRIGGER IF EXISTS transport_version ON transport_authorization_requests;
            DROP TRIGGER IF EXISTS transport_audit ON transport_authorization_requests;
            DROP TRIGGER IF EXISTS transport_no_delete ON transport_authorization_requests;
            DROP TRIGGER IF EXISTS transport_application ON transport_authorization_requests;
            DROP TRIGGER IF EXISTS transport_route_history ON transport_authorization_requests;
            DROP TRIGGER IF EXISTS transport_history ON transport_authorization_decisions;
            DROP TRIGGER IF EXISTS transport_no_truncate ON transport_authorization_decisions;
            DROP TRIGGER IF EXISTS transport_record ON transport_authorization_decisions;
            DROP TRIGGER IF EXISTS transport_version ON transport_authorization_decisions;
            DROP TRIGGER IF EXISTS transport_audit ON transport_authorization_decisions;
            DROP TRIGGER IF EXISTS transport_no_delete ON transport_authorization_decisions;
            DROP TRIGGER IF EXISTS transport_application ON transport_authorization_decisions;
            DROP TRIGGER IF EXISTS transport_route_history ON transport_authorization_decisions;
            DROP TRIGGER IF EXISTS transport_history ON transport_authorization_applications;
            DROP TRIGGER IF EXISTS transport_no_truncate ON transport_authorization_applications;
            DROP TRIGGER IF EXISTS transport_record ON transport_authorization_applications;
            DROP TRIGGER IF EXISTS transport_version ON transport_authorization_applications;
            DROP TRIGGER IF EXISTS transport_audit ON transport_authorization_applications;
            DROP TRIGGER IF EXISTS transport_no_delete ON transport_authorization_applications;
            DROP TRIGGER IF EXISTS transport_application ON transport_authorization_applications;
            DROP TRIGGER IF EXISTS transport_route_history ON transport_authorization_applications;
            DROP TRIGGER IF EXISTS transport_history ON transport_changes;
            DROP TRIGGER IF EXISTS transport_no_truncate ON transport_changes;
            DROP TRIGGER IF EXISTS transport_record ON transport_changes;
            DROP TRIGGER IF EXISTS transport_version ON transport_changes;
            DROP TRIGGER IF EXISTS transport_audit ON transport_changes;
            DROP TRIGGER IF EXISTS transport_no_delete ON transport_changes;
            DROP TRIGGER IF EXISTS transport_application ON transport_changes;
            DROP TRIGGER IF EXISTS transport_route_history ON transport_changes;
            DROP TRIGGER IF EXISTS transport_history ON trip_product_reviews;
            DROP TRIGGER IF EXISTS transport_no_truncate ON trip_product_reviews;
            DROP TRIGGER IF EXISTS transport_record ON trip_product_reviews;
            DROP TRIGGER IF EXISTS transport_version ON trip_product_reviews;
            DROP TRIGGER IF EXISTS transport_audit ON trip_product_reviews;
            DROP TRIGGER IF EXISTS transport_no_delete ON trip_product_reviews;
            DROP TRIGGER IF EXISTS transport_application ON trip_product_reviews;
            DROP TRIGGER IF EXISTS transport_route_history ON trip_product_reviews;
            DROP TRIGGER IF EXISTS transport_history ON trip_events;
            DROP TRIGGER IF EXISTS transport_no_truncate ON trip_events;
            DROP TRIGGER IF EXISTS transport_record ON trip_events;
            DROP TRIGGER IF EXISTS transport_version ON trip_events;
            DROP TRIGGER IF EXISTS transport_audit ON trip_events;
            DROP TRIGGER IF EXISTS transport_no_delete ON trip_events;
            DROP TRIGGER IF EXISTS transport_application ON trip_events;
            DROP TRIGGER IF EXISTS transport_route_history ON trip_events;
            DROP TRIGGER IF EXISTS transport_history ON trip_warnings;
            DROP TRIGGER IF EXISTS transport_no_truncate ON trip_warnings;
            DROP TRIGGER IF EXISTS transport_record ON trip_warnings;
            DROP TRIGGER IF EXISTS transport_version ON trip_warnings;
            DROP TRIGGER IF EXISTS transport_audit ON trip_warnings;
            DROP TRIGGER IF EXISTS transport_no_delete ON trip_warnings;
            DROP TRIGGER IF EXISTS transport_application ON trip_warnings;
            DROP TRIGGER IF EXISTS transport_route_history ON trip_warnings;
            DROP TRIGGER IF EXISTS transport_history ON trip_warning_resolutions;
            DROP TRIGGER IF EXISTS transport_no_truncate ON trip_warning_resolutions;
            DROP TRIGGER IF EXISTS transport_record ON trip_warning_resolutions;
            DROP TRIGGER IF EXISTS transport_version ON trip_warning_resolutions;
            DROP TRIGGER IF EXISTS transport_audit ON trip_warning_resolutions;
            DROP TRIGGER IF EXISTS transport_no_delete ON trip_warning_resolutions;
            DROP TRIGGER IF EXISTS transport_application ON trip_warning_resolutions;
            DROP TRIGGER IF EXISTS transport_route_history ON trip_warning_resolutions;
            DROP TRIGGER IF EXISTS transport_history ON transport_requests;
            DROP TRIGGER IF EXISTS transport_no_truncate ON transport_requests;
            DROP TRIGGER IF EXISTS transport_record ON transport_requests;
            DROP TRIGGER IF EXISTS transport_version ON transport_requests;
            DROP TRIGGER IF EXISTS transport_audit ON transport_requests;
            DROP TRIGGER IF EXISTS transport_no_delete ON transport_requests;
            DROP TRIGGER IF EXISTS transport_application ON transport_requests;
            DROP TRIGGER IF EXISTS transport_route_history ON transport_requests;
            DROP TRIGGER IF EXISTS transport_history ON trips;
            DROP TRIGGER IF EXISTS transport_no_truncate ON trips;
            DROP TRIGGER IF EXISTS transport_record ON trips;
            DROP TRIGGER IF EXISTS transport_version ON trips;
            DROP TRIGGER IF EXISTS transport_audit ON trips;
            DROP TRIGGER IF EXISTS transport_no_delete ON trips;
            DROP TRIGGER IF EXISTS transport_application ON trips;
            DROP TRIGGER IF EXISTS transport_route_history ON trips;
            DROP TRIGGER IF EXISTS transport_history ON routes;
            DROP TRIGGER IF EXISTS transport_no_truncate ON routes;
            DROP TRIGGER IF EXISTS transport_record ON routes;
            DROP TRIGGER IF EXISTS transport_version ON routes;
            DROP TRIGGER IF EXISTS transport_audit ON routes;
            DROP TRIGGER IF EXISTS transport_no_delete ON routes;
            DROP TRIGGER IF EXISTS transport_application ON routes;
            DROP TRIGGER IF EXISTS transport_route_history ON routes;
            DROP FUNCTION transport_preserve_history();
            DROP FUNCTION transport_require_role(bigint,boolean);
            DROP FUNCTION transport_validate_record();
            DROP FUNCTION transport_validate_application();
            DROP FUNCTION transport_validate_update();
            DROP FUNCTION transport_require_audit();
            DROP FUNCTION transport_preserve_used_route();

            SQL);
        Schema::dropIfExists('transport_authorization_applications');
    }
};
