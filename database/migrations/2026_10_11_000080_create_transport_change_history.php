<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transport_request_id')->nullable()->constrained('transport_requests')->restrictOnDelete();
            $table->foreignUuid('trip_id')->nullable()->constrained('trips')->restrictOnDelete();
            $table->index('transport_request_id');
            $table->index('trip_id');
            $table->text('action');
            $table->jsonb('before_values');
            $table->jsonb('after_values');
            $table->bigInteger('from_version');
            $table->bigInteger('to_version');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('registered_at')->useCurrent();
        });
        Schema::create('trip_product_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUuid('request_change_id')->constrained('transport_changes')->restrictOnDelete();
            $table->foreignUuid('previous_product_id')->constrained('liquid_products')->restrictOnDelete();
            $table->foreignUuid('proposed_product_id')->constrained('liquid_products')->restrictOnDelete();
            $table->text('result');
            $table->foreignId('reviewed_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('registered_at')->useCurrent();
            $table->unique(['trip_id', 'request_change_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE transport_changes ADD CONSTRAINT transport_change_target CHECK (num_nonnulls(transport_request_id,trip_id)=1),
                ADD CONSTRAINT transport_change_versions CHECK (from_version > 0 AND to_version=from_version+1),
                ADD CONSTRAINT transport_change_payload CHECK (jsonb_typeof(before_values)='object' AND jsonb_typeof(after_values)='object'),
                ADD CONSTRAINT transport_change_reason CHECK (btrim(reason)<>'' AND btrim(action)<>'');
            CREATE UNIQUE INDEX transport_request_change_version ON transport_changes(transport_request_id,to_version) WHERE transport_request_id IS NOT NULL;
            CREATE UNIQUE INDEX transport_trip_change_version ON transport_changes(trip_id,to_version) WHERE trip_id IS NOT NULL;
            ALTER TABLE trip_product_reviews ADD CONSTRAINT transport_review_result CHECK (result IN ('retained','requires_review')), ADD CONSTRAINT transport_review_reason CHECK (btrim(reason)<>'');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_product_reviews');
        Schema::dropIfExists('transport_changes');
    }
};
