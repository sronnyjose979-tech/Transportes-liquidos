<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE transport_creation_receipts (
                id uuid PRIMARY KEY,
                actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                request_key uuid NOT NULL,
                kind varchar(10) NOT NULL CHECK (kind IN ('request','trip')),
                fingerprint varchar(64) NOT NULL CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                transport_request_id uuid REFERENCES transport_requests(id) ON DELETE RESTRICT,
                trip_id uuid REFERENCES trips(id) ON DELETE RESTRICT,
                result jsonb NOT NULL CHECK (jsonb_typeof(result) = 'object'),
                registered_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (actor_id,request_key), UNIQUE (transport_request_id), UNIQUE (trip_id),
                CHECK (result ? 'id' AND result->>'id' IS NOT NULL AND ((kind='request' AND transport_request_id IS NOT NULL AND trip_id IS NULL AND result->>'id'=transport_request_id::text)
                    OR (kind='trip' AND trip_id IS NOT NULL AND transport_request_id IS NULL AND result->>'id'=trip_id::text)))
            );
            CREATE TRIGGER transport_creation_history BEFORE UPDATE OR DELETE ON transport_creation_receipts
                FOR EACH ROW EXECUTE FUNCTION transport_preserve_history();
            CREATE TRIGGER transport_creation_no_truncate BEFORE TRUNCATE ON transport_creation_receipts
                FOR EACH STATEMENT EXECUTE FUNCTION transport_preserve_history();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Creation receipts require an explicit audited rollback plan.');
    }
};
