<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION workshop_operation_destinations() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE required text[]; allowed text[]; root text; field text;
BEGIN
 root='work_order_id';
 CASE NEW.operation_type
 WHEN 'report_create','report_revise' THEN required=ARRAY[]::text[]; root='report_id';
 WHEN 'order_create','order_update','order_finish','order_close','order_cancel','order_reopen' THEN required=ARRAY[]::text[];
 WHEN 'admission_create' THEN required=ARRAY['impediment_id']; root='admission_id';
 WHEN 'admission_exit','admission_rectify' THEN required=ARRAY[]::text[]; root='admission_id';
 WHEN 'report_link_create','report_link_invalidate' THEN required=ARRAY['report_link_id'];
 WHEN 'order_admission_link_create','order_admission_link_invalidate' THEN required=ARRAY['order_admission_link_id'];
 WHEN 'mechanic_assign' THEN required=ARRAY['mechanic_assignment_id'];
 WHEN 'mechanic_release' THEN required=ARRAY['mechanic_assignment_id','mechanic_release_id'];
 WHEN 'diagnosis_record','diagnosis_revise' THEN required=ARRAY['diagnosis_id'];
 WHEN 'scope_request' THEN required=ARRAY['scope_id','authorization_request_id'];
 WHEN 'authorization_request' THEN required=ARRAY['authorization_request_id']; root=CASE WHEN NEW.work_order_id IS NULL THEN 'admission_id' ELSE 'work_order_id' END;
 WHEN 'authorization_decide' THEN required=ARRAY['authorization_request_id','authorization_decision_id']; root=CASE WHEN NEW.work_order_id IS NULL THEN 'admission_id' ELSE 'work_order_id' END;
 WHEN 'scope_apply' THEN required=ARRAY['scope_id'];
 WHEN 'activity_record','activity_revise' THEN required=ARRAY['activity_id'];
 WHEN 'time_record','time_revise' THEN required=ARRAY['time_event_id'];
 WHEN 'delivery_record','delivery_revise' THEN required=ARRAY['delivery_id'];
 WHEN 'usage_record','usage_revise' THEN required=ARRAY['usage_id'];
 WHEN 'external_record','external_revise' THEN required=ARRAY['external_repair_id'];
 WHEN 'cost_record','cost_revise','cost_classify' THEN required=ARRAY['cost_record_id'];
 WHEN 'material_need_record','material_need_revise' THEN required=ARRAY['material_need_id'];
 WHEN 'impediment_record','impediment_resolve','historical_observe','historical_resolve' THEN required=ARRAY['impediment_id']; root='fleet_asset_id';
 WHEN 'warning_note' THEN required=ARRAY['warning_id']; root='admission_id';
 WHEN 'attachment_reserve' THEN required=ARRAY[]::text[];
 WHEN 'attachment_confirm' THEN required=ARRAY['reservation_operation_id','attachment_id'];
 WHEN 'attachment_release' THEN required=ARRAY['reservation_operation_id'];
 ELSE RAISE EXCEPTION 'Tipo de operación no aprobado' USING ERRCODE='23514';
 END CASE;
 allowed=required;
 IF NEW.operation_type IN ('authorization_request','authorization_decide') THEN allowed=allowed||ARRAY['scope_id']; END IF;
 FOREACH field IN ARRAY required LOOP
  IF to_jsonb(NEW)->>field IS NULL THEN RAISE EXCEPTION 'Operación sin destino requerido' USING ERRCODE='23514'; END IF;
 END LOOP;
 FOREACH field IN ARRAY ARRAY['report_link_id','mechanic_assignment_id','mechanic_release_id','diagnosis_id','scope_id','authorization_request_id','authorization_decision_id','activity_id','time_event_id','delivery_id','usage_id','external_repair_id','attachment_id','cost_record_id','impediment_id','warning_id','material_need_id','order_admission_link_id','reservation_operation_id'] LOOP
  IF NOT(field=ANY(allowed)) AND to_jsonb(NEW)->>field IS NOT NULL THEN RAISE EXCEPTION 'Destino ajeno al tipo de operación' USING ERRCODE='23514'; END IF;
 END LOOP;
 IF to_jsonb(NEW)->>root IS NULL THEN RAISE EXCEPTION 'Raíz incompatible con operación' USING ERRCODE='23514'; END IF;
 IF NEW.from_version IS NOT NULL AND NEW.operation_type NOT IN ('order_update','order_finish','order_close','order_cancel','order_reopen','admission_exit','admission_rectify','scope_request','scope_apply','activity_record','time_record') THEN RAISE EXCEPTION 'Hecho inmutable no incrementa versión de raíz' USING ERRCODE='23514'; END IF;
 IF NEW.operation_type IN ('order_update','order_finish','order_close','order_cancel','order_reopen','admission_exit','admission_rectify') AND NEW.from_version IS NULL THEN RAISE EXCEPTION 'Cambio de raíz requiere versiones auditables' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER workshop_operation_destinations BEFORE INSERT ON workshop_operations FOR EACH ROW EXECUTE FUNCTION workshop_operation_destinations();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER workshop_operation_destinations ON workshop_operations');
        DB::statement('DROP FUNCTION workshop_operation_destinations()');
    }
};
