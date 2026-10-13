<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION workshop_asset_projection(asset uuid) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN bool_or(i.effect='out_of_service') THEN 'out_of_service' WHEN count(*)>0 THEN 'maintenance' ELSE 'available' END
 FROM fleet_operating_impediments i WHERE i.fleet_asset_id=asset AND NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions r WHERE r.impediment_id=i.id);
$$;
CREATE FUNCTION workshop_exit_content(admission uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('admission',to_jsonb(a),'causes',COALESCE((SELECT jsonb_agg(to_jsonb(i) ORDER BY i.id) FROM fleet_operating_impediments i WHERE i.fleet_asset_id=a.fleet_asset_id AND NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions r WHERE r.impediment_id=i.id)),'[]'::jsonb),
 'links',COALESCE((SELECT jsonb_agg(to_jsonb(l) ORDER BY l.id) FROM workshop_order_admission_links l WHERE l.admission_id=a.id AND NOT EXISTS(SELECT 1 FROM workshop_operations o WHERE o.order_admission_link_id=l.id AND o.operation_type='order_admission_link_invalidate')),'[]'::jsonb),
 'orders',COALESCE((SELECT jsonb_agg(to_jsonb(w) ORDER BY w.id) FROM workshop_work_orders w WHERE w.fleet_asset_id=a.fleet_asset_id),'[]'::jsonb)) FROM workshop_admissions a WHERE a.id=admission;
$$;
CREATE FUNCTION workshop_availability_record() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a workshop_admissions; cause fleet_operating_impediments; asset fleet_assets;
BEGIN
 IF TG_TABLE_NAME='workshop_admissions' THEN
  IF TG_OP='INSERT' THEN
   PERFORM workshop_require_actor(NEW.admitted_by_id,ARRAY['administrator','secretary']);
   SELECT * INTO asset FROM fleet_assets WHERE id=NEW.fleet_asset_id FOR UPDATE;
   IF asset.service_status IS DISTINCT FROM workshop_asset_projection(asset.id) THEN RAISE EXCEPTION 'Conciliar estado histórico antes del ingreso' USING ERRCODE='23514'; END IF;
   IF NEW.version<>1 THEN RAISE EXCEPTION 'Versión inicial inválida' USING ERRCODE='23514'; END IF;
  ELSE
   IF (to_jsonb(NEW)-'version') IS DISTINCT FROM (to_jsonb(OLD)-'version') OR NEW.version<>OLD.version+1 THEN RAISE EXCEPTION 'Cambio de ingreso no habilitado o versión inválida' USING ERRCODE='23514'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='workshop_order_admission_links' THEN
  PERFORM workshop_require_actor(NEW.linked_by_id,ARRAY['administrator','secretary']);
 ELSIF TG_TABLE_NAME='fleet_operating_impediments' THEN
  PERFORM workshop_require_actor(NEW.actor_id,CASE WHEN NEW.kind='historical_observation' THEN ARRAY['administrator'] ELSE ARRAY['administrator','secretary'] END);
  SELECT * INTO asset FROM fleet_assets WHERE id=NEW.fleet_asset_id FOR UPDATE;
  IF NEW.kind='workshop' AND asset.service_status IS DISTINCT FROM workshop_asset_projection(asset.id) THEN RAISE EXCEPTION 'Conciliar estado histórico antes de registrar causa' USING ERRCODE='23514'; END IF;
  IF NEW.kind='reviewed_other_cause' THEN RAISE EXCEPTION 'Alta genérica de causa no habilitada' USING ERRCODE='23514'; END IF;
  IF NEW.kind='historical_observation' AND (asset.service_status='available' OR NEW.effect IS DISTINCT FROM asset.service_status OR asset.service_status=workshop_asset_projection(asset.id)) THEN RAISE EXCEPTION 'Observación no corresponde al estado desconocido actual' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='fleet_impediment_resolutions' THEN
  PERFORM workshop_require_actor(NEW.resolved_by_id,ARRAY['administrator']);
  SELECT * INTO cause FROM fleet_operating_impediments WHERE id=NEW.impediment_id FOR UPDATE;
  IF NEW.resolved_at<cause.occurred_at THEN RAISE EXCEPTION 'Resolución anterior a causa' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='workshop_admission_exits' THEN
  PERFORM workshop_require_actor(NEW.recorded_by_id,ARRAY['administrator']);
  SELECT * INTO a FROM workshop_admissions WHERE id=NEW.admission_id FOR UPDATE;
  IF NEW.exited_at<a.admitted_at THEN RAISE EXCEPTION 'Salida anterior al ingreso' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='workshop_warnings' THEN
  PERFORM workshop_require_actor(NEW.captured_by_id,ARRAY['administrator','secretary']);
  SELECT * INTO a FROM workshop_admissions WHERE id=NEW.admission_id;
  IF NEW.trip_asset_assignment_id IS NULL OR NOT EXISTS(SELECT 1 FROM trip_asset_assignments x WHERE x.id=NEW.trip_asset_assignment_id AND x.fleet_asset_id=a.fleet_asset_id AND NOT EXISTS(SELECT 1 FROM trip_asset_releases r WHERE r.assignment_id=x.id)) OR NEW.context->>'assignment_id' IS DISTINCT FROM NEW.trip_asset_assignment_id::text OR NEW.context->>'trip_id' IS DISTINCT FROM (SELECT trip_id::text FROM trip_asset_assignments WHERE id=NEW.trip_asset_assignment_id) OR (NEW.work_order_id IS NOT NULL AND (SELECT fleet_asset_id FROM workshop_work_orders WHERE id=NEW.work_order_id) IS DISTINCT FROM a.fleet_asset_id) THEN RAISE EXCEPTION 'Advertencia ajena o asignación no vigente' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION workshop_fleet_status_record() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.service_status<>'available' THEN RAISE EXCEPTION 'Activo nuevo requiere estado available' USING ERRCODE='23514'; END IF;
 ELSIF NEW.service_status IS DISTINCT FROM OLD.service_status THEN
  IF NOT EXISTS(SELECT 1 FROM fleet_operating_impediments WHERE fleet_asset_id=OLD.id) AND OLD.service_status IS DISTINCT FROM workshop_asset_projection(OLD.id) AND
   ((OLD.service_status='out_of_service' AND NEW.service_status<>'out_of_service') OR (OLD.service_status='maintenance' AND NEW.service_status='available')) THEN
   RAISE EXCEPTION 'No liberar estado histórico sin observación administrativa' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION workshop_availability_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE asset_id uuid; a workshop_admissions; cause fleet_operating_impediments; op workshop_operations; req workshop_authorization_requests; application workshop_authorization_applications; total integer;
BEGIN
 IF TG_TABLE_NAME='fleet_assets' THEN
  IF TG_OP='UPDATE' AND NEW.service_status IS NOT DISTINCT FROM OLD.service_status THEN RETURN NULL; END IF;
  asset_id=NEW.id;
 ELSIF TG_TABLE_NAME='workshop_admissions' THEN
  asset_id=NEW.fleet_asset_id;
  IF TG_OP='INSERT' THEN
   SELECT count(*) INTO total FROM workshop_operations WHERE admission_id=NEW.id AND operation_type='admission_create' AND actor_id=NEW.admitted_by_id;
   IF total<>1 OR (SELECT count(*) FROM fleet_operating_impediments WHERE admission_id=NEW.id AND work_order_id IS NULL AND kind='workshop')<>1 THEN RAISE EXCEPTION 'Ingreso sin causa/auditoría única' USING ERRCODE='23514'; END IF;
   IF EXISTS(SELECT 1 FROM trip_asset_assignments x WHERE x.fleet_asset_id=asset_id AND NOT EXISTS(SELECT 1 FROM trip_asset_releases r WHERE r.assignment_id=x.id) AND NOT EXISTS(SELECT 1 FROM workshop_warnings w WHERE w.admission_id=NEW.id AND w.trip_asset_assignment_id=x.id AND w.code='assigned_trip')) THEN RAISE EXCEPTION 'Ingreso sin capturar asignación existente' USING ERRCODE='23514'; END IF;
  ELSE
   SELECT * INTO op FROM workshop_operations WHERE admission_id=NEW.id AND from_version=OLD.version AND to_version=NEW.version;
   IF op.id IS NULL OR op.operation_type<>'admission_exit' OR op.before_values IS DISTINCT FROM to_jsonb(OLD) OR op.after_values IS DISTINCT FROM to_jsonb(NEW) THEN RAISE EXCEPTION 'Versión de ingreso sin salida exacta' USING ERRCODE='23514'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='fleet_operating_impediments' THEN
  asset_id=NEW.fleet_asset_id;
  IF (SELECT count(*) FROM workshop_operations WHERE impediment_id=NEW.id AND operation_type IN ('admission_create','historical_observe','impediment_record'))<>1 THEN RAISE EXCEPTION 'Causa sin auditoría única' USING ERRCODE='23514'; END IF;
  SELECT * INTO op FROM workshop_operations WHERE impediment_id=NEW.id AND operation_type IN ('admission_create','historical_observe','impediment_record');
  IF op.id IS NULL OR op.actor_id<>NEW.actor_id OR (NEW.kind='historical_observation' AND op.operation_type<>'historical_observe') OR (NEW.kind='workshop' AND NEW.work_order_id IS NULL AND op.admission_id IS DISTINCT FROM NEW.admission_id) THEN RAISE EXCEPTION 'Causa sin operación coherente' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='fleet_impediment_resolutions' THEN
  SELECT * INTO cause FROM fleet_operating_impediments WHERE id=NEW.impediment_id; asset_id=cause.fleet_asset_id;
  SELECT * INTO op FROM workshop_operations WHERE id=NEW.operation_id;
  IF op.actor_id IS DISTINCT FROM NEW.resolved_by_id OR (op.operation_type<>'admission_exit' AND op.impediment_id IS DISTINCT FROM cause.id) OR
    (cause.kind='historical_observation' AND op.operation_type<>'historical_resolve') OR (cause.kind='workshop' AND op.operation_type NOT IN ('impediment_resolve','admission_exit')) OR
    (cause.kind='workshop' AND cause.admission_id IS NOT NULL AND cause.work_order_id IS NULL AND (op.operation_type<>'admission_exit' OR op.admission_id IS DISTINCT FROM cause.admission_id OR NOT EXISTS(SELECT 1 FROM workshop_admission_exits WHERE admission_id=cause.admission_id))) THEN RAISE EXCEPTION 'Resolución sin operación autorizada exacta' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='workshop_admission_exits' THEN
  SELECT * INTO a FROM workshop_admissions WHERE id=NEW.admission_id; asset_id=a.fleet_asset_id;
  SELECT * INTO application FROM workshop_authorization_applications WHERE id=NEW.authorization_application_id;
  SELECT q.* INTO req FROM workshop_authorization_requests q JOIN workshop_authorization_decisions d ON d.request_id=q.id WHERE d.id=application.decision_id;
  SELECT * INTO op FROM workshop_operations WHERE id=application.operation_id;
  IF req.type<>'exit' OR req.admission_id IS DISTINCT FROM NEW.admission_id OR op.operation_type<>'admission_exit' OR op.actor_id IS DISTINCT FROM NEW.recorded_by_id OR NEW.reviewed_content IS DISTINCT FROM req.content THEN RAISE EXCEPTION 'Salida de otra autorización' USING ERRCODE='23514'; END IF;
  IF EXISTS(SELECT 1 FROM fleet_operating_impediments i WHERE i.kind='workshop' AND i.work_order_id IS NOT NULL AND (i.admission_id=a.id OR EXISTS(SELECT 1 FROM workshop_order_admission_links l WHERE l.admission_id=a.id AND l.work_order_id=i.work_order_id AND NOT EXISTS(SELECT 1 FROM workshop_operations o WHERE o.order_admission_link_id=l.id AND o.operation_type='order_admission_link_invalidate'))) AND NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions r WHERE r.impediment_id=i.id)) THEN RAISE EXCEPTION 'Reparación vinculada no resuelta' USING ERRCODE='23514'; END IF;
  IF EXISTS(SELECT 1 FROM fleet_operating_impediments i WHERE i.admission_id=a.id AND i.work_order_id IS NULL AND i.kind='workshop' AND NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions r WHERE r.impediment_id=i.id AND r.operation_id=op.id)) THEN RAISE EXCEPTION 'Salida sin resolver causa canónica' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='workshop_order_admission_links' THEN
  SELECT * INTO op FROM workshop_operations WHERE id=NEW.operation_id;
  IF op.operation_type<>'order_admission_link_create' OR op.order_admission_link_id IS DISTINCT FROM NEW.id OR op.work_order_id IS DISTINCT FROM NEW.work_order_id OR op.actor_id<>NEW.linked_by_id THEN RAISE EXCEPTION 'Vínculo sin operación exacta' USING ERRCODE='23514'; END IF;
  RETURN NULL;
 ELSIF TG_TABLE_NAME='workshop_warnings' THEN
  IF NOT EXISTS(SELECT 1 FROM workshop_operations WHERE admission_id=NEW.admission_id AND operation_type='admission_create' AND actor_id=NEW.captured_by_id) THEN RAISE EXCEPTION 'Advertencia sin ingreso del mismo actor' USING ERRCODE='23514'; END IF;
  RETURN NULL;
 ELSE RETURN NULL;
 END IF;
 IF EXISTS(SELECT 1 FROM workshop_admissions x WHERE x.fleet_asset_id=asset_id AND NOT EXISTS(SELECT 1 FROM workshop_admission_exits e WHERE e.admission_id=x.id) GROUP BY x.fleet_asset_id HAVING count(*)>1) THEN RAISE EXCEPTION 'Más de un ingreso abierto' USING ERRCODE='23514'; END IF;
 IF (SELECT service_status FROM fleet_assets WHERE id=asset_id) IS DISTINCT FROM workshop_asset_projection(asset_id) THEN RAISE EXCEPTION 'Proyección operativa incoherente' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
CREATE FUNCTION workshop_availability_operation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cause fleet_operating_impediments; warning workshop_warnings; link workshop_order_admission_links;
BEGIN
 IF NEW.operation_type IN ('admission_create','admission_exit') THEN
  IF NEW.admission_id IS NULL OR NEW.from_version IS NULL AND NEW.operation_type='admission_exit' THEN RAISE EXCEPTION 'Raíz/versión ingreso inválida' USING ERRCODE='23514'; END IF;
  IF NEW.operation_type='admission_create' THEN
   SELECT * INTO cause FROM fleet_operating_impediments WHERE id=NEW.impediment_id;
   IF cause.id IS NULL OR cause.admission_id IS DISTINCT FROM NEW.admission_id OR cause.work_order_id IS NOT NULL OR cause.actor_id<>NEW.actor_id OR NEW.to_version IS NOT NULL THEN RAISE EXCEPTION 'Creación sin causa propia' USING ERRCODE='23514'; END IF;
  ELSE
   IF NEW.impediment_id IS NOT NULL OR NOT EXISTS(SELECT 1 FROM workshop_authorization_applications WHERE operation_id=NEW.id) THEN RAISE EXCEPTION 'Salida sin aplicación' USING ERRCODE='23514'; END IF;
  END IF;
 ELSIF NEW.operation_type IN ('historical_observe','historical_resolve','impediment_record','impediment_resolve') THEN
  SELECT * INTO cause FROM fleet_operating_impediments WHERE id=NEW.impediment_id;
  IF cause.id IS NULL OR NEW.fleet_asset_id IS DISTINCT FROM cause.fleet_asset_id OR NEW.to_version IS NOT NULL OR ((NEW.operation_type IN ('historical_observe','historical_resolve')) IS DISTINCT FROM (cause.kind='historical_observation')) THEN RAISE EXCEPTION 'Operación de otra causa/activo' USING ERRCODE='23514'; END IF;
  IF NEW.operation_type IN ('historical_observe','impediment_record') THEN
   IF cause.actor_id<>NEW.actor_id THEN RAISE EXCEPTION 'Actor ajeno a causa' USING ERRCODE='23514'; END IF;
  ELSIF NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions WHERE impediment_id=cause.id AND operation_id=NEW.id AND resolved_by_id=NEW.actor_id) THEN RAISE EXCEPTION 'Operación sin resolución' USING ERRCODE='23514'; END IF;
 ELSIF NEW.operation_type IN ('order_admission_link_create','order_admission_link_invalidate') THEN
  SELECT * INTO link FROM workshop_order_admission_links WHERE id=NEW.order_admission_link_id;
  IF link.id IS NULL OR link.work_order_id IS DISTINCT FROM NEW.work_order_id THEN RAISE EXCEPTION 'Vínculo de otra orden' USING ERRCODE='23514'; END IF;
 ELSIF NEW.operation_type='warning_note' THEN
  SELECT * INTO warning FROM workshop_warnings WHERE id=NEW.warning_id;
  IF warning.id IS NULL OR warning.admission_id IS DISTINCT FROM NEW.admission_id THEN RAISE EXCEPTION 'Nota de otro ingreso' USING ERRCODE='23514'; END IF;
 END IF;
 IF num_nonnulls(NEW.report_link_id,NEW.mechanic_assignment_id,NEW.mechanic_release_id,NEW.diagnosis_id,NEW.scope_id,NEW.authorization_request_id,NEW.authorization_decision_id)<>0 OR
   (NEW.operation_type NOT IN ('order_admission_link_create','order_admission_link_invalidate') AND NEW.order_admission_link_id IS NOT NULL) OR
   (NEW.operation_type<>'warning_note' AND NEW.warning_id IS NOT NULL) OR (NEW.operation_type NOT IN ('admission_create','historical_observe','historical_resolve','impediment_record','impediment_resolve') AND NEW.impediment_id IS NOT NULL) THEN RAISE EXCEPTION 'Destinos adicionales incompatibles' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
CREATE FUNCTION workshop_availability_destinations() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF num_nonnulls(NEW.impediment_id,NEW.warning_id)<>0 THEN RAISE EXCEPTION 'Destino de disponibilidad inesperado' USING ERRCODE='23514'; END IF; RETURN NEW; END; $$;
CREATE FUNCTION workshop_availability_actor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM workshop_require_actor(NEW.actor_id,CASE WHEN NEW.operation_type IN ('admission_create','impediment_record','order_admission_link_create','order_admission_link_invalidate','warning_note') THEN ARRAY['administrator','secretary'] ELSE ARRAY['administrator'] END);
 RETURN NEW;
END; $$;
CREATE FUNCTION workshop_exit_request() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.content->'review' IS DISTINCT FROM workshop_exit_content(NEW.admission_id) OR jsonb_typeof(NEW.content->'resolve_ids') IS DISTINCT FROM 'array' OR jsonb_typeof(NEW.content->'after') IS DISTINCT FROM 'object' OR NEW.content->'after' IS DISTINCT FROM (SELECT to_jsonb(a)||jsonb_build_object('version',a.version+1) FROM workshop_admissions a WHERE a.id=NEW.admission_id) OR EXISTS(SELECT 1 FROM workshop_admission_exits WHERE admission_id=NEW.admission_id) THEN RAISE EXCEPTION 'Revisión de salida obsoleta/incompleta' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements_text(NEW.content->'resolve_ids') x WHERE NOT EXISTS(SELECT 1 FROM fleet_operating_impediments i JOIN workshop_admissions a ON a.fleet_asset_id=i.fleet_asset_id WHERE a.id=NEW.admission_id AND i.id=x::uuid AND i.kind='workshop' AND NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions r WHERE r.impediment_id=i.id))) OR EXISTS(SELECT 1 FROM fleet_operating_impediments i WHERE i.admission_id=NEW.admission_id AND i.work_order_id IS NULL AND i.kind='workshop' AND NOT (NEW.content->'resolve_ids' ? i.id::text)) THEN RAISE EXCEPTION 'Resoluciones propuestas incompatibles' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION workshop_exit_application() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE req workshop_authorization_requests; decision workshop_authorization_decisions; op workshop_operations; original jsonb; current_review jsonb; approved_ids jsonb;
BEGIN
 SELECT * INTO decision FROM workshop_authorization_decisions WHERE id=NEW.decision_id;
 SELECT * INTO req FROM workshop_authorization_requests WHERE id=decision.request_id;
 SELECT * INTO op FROM workshop_operations WHERE id=NEW.operation_id;
 IF req.type<>'exit' THEN RETURN NULL; END IF;
 IF decision.decision<>'approved' OR op.operation_type<>'admission_exit' OR op.admission_id IS DISTINCT FROM req.admission_id OR op.actor_id IS DISTINCT FROM NEW.applied_by_id OR op.from_version IS DISTINCT FROM req.target_version OR op.after_values IS DISTINCT FROM req.content->'after' THEN RAISE EXCEPTION 'Salida no autorizada/versionada' USING ERRCODE='23514'; END IF;
 original=req.content->'review'; approved_ids=req.content->'resolve_ids';
 current_review=workshop_exit_content(req.admission_id);
 current_review=jsonb_set(current_review,'{admission}',op.before_values);
 -- Include causes resolved by this operation, preserving the exact reviewed snapshot.
 current_review=jsonb_set(current_review,'{causes}',COALESCE((SELECT jsonb_agg(to_jsonb(i) ORDER BY i.id) FROM fleet_operating_impediments i JOIN workshop_admissions a ON a.fleet_asset_id=i.fleet_asset_id WHERE a.id=req.admission_id AND (NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions r WHERE r.impediment_id=i.id) OR EXISTS(SELECT 1 FROM fleet_impediment_resolutions r WHERE r.impediment_id=i.id AND r.operation_id=op.id))),'[]'::jsonb));
 IF current_review IS DISTINCT FROM original OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(approved_ids) x WHERE NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions r WHERE r.impediment_id=x::uuid AND r.operation_id=op.id)) OR EXISTS(SELECT 1 FROM fleet_impediment_resolutions r WHERE r.operation_id=op.id AND NOT (approved_ids ? r.impediment_id::text)) THEN RAISE EXCEPTION 'Causas/órdenes/resoluciones distintas de aprobación' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END; $$;
SQL);
        $types = "'admission_create','admission_exit','historical_observe','historical_resolve','impediment_record','impediment_resolve','order_admission_link_create','order_admission_link_invalidate','warning_note'";
        DB::statement("CREATE TRIGGER workshop_phase3_destinations BEFORE INSERT ON workshop_operations FOR EACH ROW WHEN (NEW.operation_type NOT IN ({$types})) EXECUTE FUNCTION workshop_availability_destinations()");
        DB::statement('DROP TRIGGER workshop_operation ON workshop_operations');
        DB::statement("CREATE CONSTRAINT TRIGGER workshop_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation_type NOT IN ('scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen',{$types})) EXECUTE FUNCTION workshop_operation_integrity_core()");
        DB::statement('DROP TRIGGER workshop_actor ON workshop_operations');
        DB::statement("CREATE TRIGGER workshop_actor BEFORE INSERT ON workshop_operations FOR EACH ROW WHEN (NEW.operation_type NOT IN ('scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen',{$types})) EXECUTE FUNCTION workshop_operation_actor()");
        DB::statement("CREATE TRIGGER workshop_phase3_actor BEFORE INSERT ON workshop_operations FOR EACH ROW WHEN (NEW.operation_type IN ({$types})) EXECUTE FUNCTION workshop_availability_actor()");
        DB::statement("CREATE CONSTRAINT TRIGGER workshop_phase3_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation_type IN ({$types})) EXECUTE FUNCTION workshop_availability_operation()");
        DB::statement('DROP TRIGGER workshop_audit ON workshop_authorization_applications');
        DB::statement('CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT ON workshop_authorization_applications DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_exit_application()');
        // Phase 2 remains responsible for non-exit applications through a dispatch function.
        DB::unprepared(<<<'SQL'
ALTER FUNCTION workshop_authorization_audit() RENAME TO workshop_authorization_audit_phase2;
SQL);
        // A WHEN predicate cannot contain a subquery; dispatch with two triggers and
        // a row-local request-type discriminator obtained by the helper below.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION workshop_application_is_exit(decision uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT q.type='exit' FROM workshop_authorization_decisions d JOIN workshop_authorization_requests q ON q.id=d.request_id WHERE d.id=$1 $$;
CREATE CONSTRAINT TRIGGER workshop_phase2_application AFTER INSERT ON workshop_authorization_applications DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NOT workshop_application_is_exit(NEW.decision_id)) EXECUTE FUNCTION workshop_authorization_audit_phase2();
SQL);
        foreach (['workshop_admissions', 'workshop_order_admission_links'] as $table) {
            DB::statement("DROP TRIGGER workshop_record ON {$table}");
            if ($table === 'workshop_admissions') {
                DB::statement('DROP TRIGGER workshop_history ON workshop_admissions');
                DB::statement('CREATE TRIGGER workshop_history BEFORE DELETE ON workshop_admissions FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history()');
            }
            DB::statement('CREATE TRIGGER workshop_record BEFORE INSERT'.($table === 'workshop_admissions' ? ' OR UPDATE' : '')." ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_availability_record()");
            DB::statement('CREATE CONSTRAINT TRIGGER workshop_availability_audit AFTER INSERT'.($table === 'workshop_admissions' ? ' OR UPDATE' : '')." ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_availability_audit()");
        }
        foreach (['fleet_operating_impediments', 'fleet_impediment_resolutions', 'workshop_admission_exits', 'workshop_warnings'] as $table) {
            DB::statement("CREATE TRIGGER workshop_lock BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock()");
            DB::statement("CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history()");
            DB::statement("CREATE TRIGGER workshop_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION workshop_preserve_history()");
            DB::statement("CREATE TRIGGER workshop_record BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_availability_record()");
            DB::statement("CREATE CONSTRAINT TRIGGER workshop_availability_audit AFTER INSERT ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_availability_audit()");
        }
        DB::statement('CREATE TRIGGER workshop_projection_lock BEFORE INSERT OR UPDATE ON fleet_assets FOR EACH STATEMENT EXECUTE FUNCTION transport_assignment_lock()');
        DB::statement('CREATE TRIGGER workshop_projection_record BEFORE INSERT OR UPDATE ON fleet_assets FOR EACH ROW EXECUTE FUNCTION workshop_fleet_status_record()');
        DB::statement('CREATE CONSTRAINT TRIGGER workshop_projection_audit AFTER INSERT OR UPDATE ON fleet_assets DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_availability_audit()');
        DB::statement("CREATE TRIGGER workshop_exit_request BEFORE INSERT ON workshop_authorization_requests FOR EACH ROW WHEN (NEW.type='exit') EXECUTE FUNCTION workshop_exit_request()");
    }

    public function down(): void
    {
        foreach (['workshop_projection_lock', 'workshop_projection_record', 'workshop_projection_audit'] as $trigger) {
            DB::statement("DROP TRIGGER {$trigger} ON fleet_assets");
        }
        DB::statement('DROP TRIGGER workshop_exit_request ON workshop_authorization_requests');
        DB::statement('DROP TRIGGER workshop_phase2_application ON workshop_authorization_applications');
        DB::statement('DROP TRIGGER workshop_audit ON workshop_authorization_applications');
        DB::statement('ALTER FUNCTION workshop_authorization_audit_phase2() RENAME TO workshop_authorization_audit');
        DB::statement('CREATE CONSTRAINT TRIGGER workshop_audit AFTER INSERT ON workshop_authorization_applications DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_authorization_audit()');
        foreach (['workshop_admissions', 'workshop_order_admission_links'] as $table) {
            DB::statement("DROP TRIGGER workshop_record ON {$table}");
            DB::statement("DROP TRIGGER workshop_availability_audit ON {$table}");
            if ($table === 'workshop_admissions') {
                DB::statement('DROP TRIGGER workshop_history ON workshop_admissions');
                DB::statement('CREATE TRIGGER workshop_history BEFORE UPDATE OR DELETE ON workshop_admissions FOR EACH ROW EXECUTE FUNCTION workshop_preserve_history()');
            }
            DB::statement('CREATE TRIGGER workshop_record BEFORE INSERT'.($table === 'workshop_admissions' ? ' OR UPDATE' : '')." ON {$table} FOR EACH ROW EXECUTE FUNCTION workshop_core_record()");
        }
        foreach (['fleet_operating_impediments', 'fleet_impediment_resolutions', 'workshop_admission_exits', 'workshop_warnings'] as $table) {
            foreach (['workshop_lock', 'workshop_history', 'workshop_no_truncate', 'workshop_record', 'workshop_availability_audit'] as $trigger) {
                DB::statement("DROP TRIGGER {$trigger} ON {$table}");
            }
        }
        DB::statement('DROP TRIGGER workshop_phase3_destinations ON workshop_operations');
        DB::statement('DROP TRIGGER workshop_phase3_actor ON workshop_operations');
        DB::statement('DROP TRIGGER workshop_phase3_operation ON workshop_operations');
        DB::statement('DROP TRIGGER workshop_actor ON workshop_operations');
        DB::statement('DROP TRIGGER workshop_operation ON workshop_operations');
        DB::statement("CREATE TRIGGER workshop_actor BEFORE INSERT ON workshop_operations FOR EACH ROW WHEN (NEW.operation_type NOT IN ('scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen')) EXECUTE FUNCTION workshop_operation_actor()");
        DB::statement("CREATE CONSTRAINT TRIGGER workshop_operation AFTER INSERT ON workshop_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operation_type NOT IN ('scope_request','authorization_request','authorization_decide','scope_apply','order_cancel','order_reopen')) EXECUTE FUNCTION workshop_operation_integrity_core()");
        foreach (['workshop_application_is_exit(uuid)', 'workshop_exit_application()', 'workshop_exit_request()', 'workshop_availability_destinations()', 'workshop_availability_actor()', 'workshop_availability_operation()', 'workshop_availability_audit()', 'workshop_fleet_status_record()', 'workshop_availability_record()', 'workshop_exit_content(uuid)', 'workshop_asset_projection(uuid)'] as $function) {
            DB::statement("DROP FUNCTION {$function}");
        }
    }
};
