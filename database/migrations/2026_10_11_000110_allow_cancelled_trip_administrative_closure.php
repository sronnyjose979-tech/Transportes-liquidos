<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE trips
                DROP CONSTRAINT transport_trip_close_after_completion,
                DROP CONSTRAINT transport_trip_chronology,
                ADD CONSTRAINT transport_trip_close_after_terminal_state
                    CHECK (administrative_status <> 'closed' OR execution_status IN ('completed', 'cancelled')),
                ADD CONSTRAINT transport_trip_chronology
                    CHECK (
                        (officially_completed_at IS NULL OR officially_completed_at >= officially_started_at)
                        AND (
                            closed_at IS NULL
                            OR (execution_status = 'completed' AND closed_at >= officially_completed_at)
                            OR (execution_status = 'cancelled' AND closed_at >= cancelled_at)
                        )
                    );
            SQL);
    }

    public function down(): void
    {
        // PostgreSQL refuses restoration if closed cancelled trips already exist.
        // Never rewrite their history to make an incompatible rollback succeed.
        DB::unprepared(<<<'SQL'
            ALTER TABLE trips
                DROP CONSTRAINT transport_trip_close_after_terminal_state,
                DROP CONSTRAINT transport_trip_chronology,
                ADD CONSTRAINT transport_trip_close_after_completion
                    CHECK (administrative_status <> 'closed' OR officially_completed_at IS NOT NULL),
                ADD CONSTRAINT transport_trip_chronology
                    CHECK ((officially_completed_at IS NULL OR officially_completed_at >= officially_started_at)
                        AND (closed_at IS NULL OR closed_at >= officially_completed_at));
            SQL);
    }
};
