<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION purchasing_unit_compatibility() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(873210);
 UPDATE transport_assignment_guard SET version=version+1 WHERE id=1;
 IF TG_TABLE_NAME='spare_parts' THEN
  IF NEW.unit IS DISTINCT FROM OLD.unit AND EXISTS(
   SELECT 1 FROM purchase_request_items i JOIN purchase_request_decisions d ON d.purchase_request_id=i.purchase_request_id
   WHERE i.spare_part_id=OLD.id AND d.decision='approved' AND i.unit_snapshot IS DISTINCT FROM NEW.unit
  ) THEN RAISE EXCEPTION 'La unidad está vinculada a una compra autorizada; no hay conversión aprobada' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.decision='approved' AND EXISTS(
   SELECT 1 FROM purchase_request_items i JOIN spare_parts p ON p.id=i.spare_part_id
   WHERE i.purchase_request_id=NEW.purchase_request_id AND i.unit_snapshot IS DISTINCT FROM p.unit
  ) THEN RAISE EXCEPTION 'La unidad cambió desde la solicitud; revisar cantidades antes de autorizar' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER purchasing_unit_compatibility BEFORE UPDATE OF unit ON spare_parts FOR EACH ROW EXECUTE FUNCTION purchasing_unit_compatibility();
CREATE TRIGGER purchasing_unit_compatibility BEFORE INSERT ON purchase_request_decisions FOR EACH ROW EXECUTE FUNCTION purchasing_unit_compatibility();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER purchasing_unit_compatibility ON spare_parts; DROP TRIGGER purchasing_unit_compatibility ON purchase_request_decisions; DROP FUNCTION purchasing_unit_compatibility();');
    }
};
