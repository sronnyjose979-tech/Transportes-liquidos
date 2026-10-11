<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_authorization_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transport_request_id')->nullable()->constrained('transport_requests')->restrictOnDelete();
            $table->foreignUuid('trip_id')->nullable()->constrained('trips')->restrictOnDelete();
            $table->index('transport_request_id');
            $table->index('trip_id');
            $table->text('type');
            $table->jsonb('content');
            $table->bigInteger('target_version');
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('registered_at')->useCurrent();
        });
        Schema::create('transport_authorization_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('authorization_request_id')->constrained('transport_authorization_requests')->restrictOnDelete();
            $table->unique('authorization_request_id');
            $table->text('decision');
            $table->foreignId('decided_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('registered_at')->useCurrent();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE transport_authorization_requests ADD CONSTRAINT transport_auth_target CHECK (num_nonnulls(transport_request_id,trip_id)=1),
                ADD CONSTRAINT transport_auth_version CHECK (target_version > 0),
                ADD CONSTRAINT transport_auth_content CHECK (jsonb_typeof(content)='object'),
                ADD CONSTRAINT transport_auth_reason CHECK (btrim(reason) <> ''),
                ADD CONSTRAINT transport_auth_type CHECK (type IN ('product_change','volume_difference','price_change','expense_without_receipt','allowance_disbursement','cancellation_charge')),
                ADD CONSTRAINT transport_auth_type_target CHECK ((type='product_change' AND transport_request_id IS NOT NULL) OR (type<>'product_change' AND trip_id IS NOT NULL));
            ALTER TABLE transport_authorization_decisions ADD CONSTRAINT transport_auth_decision CHECK (decision IN ('approved','rejected')), ADD CONSTRAINT transport_decision_reason CHECK (btrim(reason)<>'');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_authorization_decisions');
        Schema::dropIfExists('transport_authorization_requests');
    }
};
