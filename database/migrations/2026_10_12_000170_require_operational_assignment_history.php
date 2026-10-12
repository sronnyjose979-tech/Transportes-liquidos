<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER FUNCTION trip_require_completion(uuid) RENAME TO trip_require_completion_base;
            CREATE FUNCTION trip_require_completion(target uuid) RETURNS void LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM trip_require_completion_base(target);
                IF NOT EXISTS (SELECT 1 FROM trips t JOIN transport_changes c ON c.trip_id=t.id
                    WHERE t.id=target AND c.before_values->>'execution_status'='pending'
                    AND c.after_values->>'execution_status'='in_progress'
                    AND (c.after_values->>'started_by_id')::bigint=t.started_by_id
                    AND (c.after_values->>'officially_started_at')::timestamptz=t.officially_started_at)
                    OR NOT EXISTS (SELECT 1 FROM trip_loads l JOIN trip_asset_assignments a ON a.id=l.tanker_assignment_id
                        JOIN transport_assignment_operations o ON o.id=a.operation_id
                        WHERE l.trip_id=target AND a.trip_id=target AND a.asset_type='tanker' AND o.trip_id=target
                        AND a.starts_at<=l.registered_at AND NOT EXISTS
                            (SELECT 1 FROM trip_asset_releases r WHERE r.assignment_id=a.id AND r.ends_at<=l.registered_at)) THEN
                    RAISE EXCEPTION 'Completion requires audited official start and matching operational tanker history' USING ERRCODE='23514';
                END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Reversión requiere revisar el historial operativo sin eliminarlo.');
    }
};
