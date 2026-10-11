<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('origin_site_id')->constrained('operation_sites')->restrictOnDelete();
            $table->foreignUuid('destination_site_id')->constrained('operation_sites')->restrictOnDelete();
            $table->text('name');
            $table->index('origin_site_id');
            $table->index('destination_site_id');
        });
        Schema::create('transport_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('liquid_product_id')->constrained('liquid_products')->restrictOnDelete();
            $table->foreignUuid('route_id')->constrained('routes')->restrictOnDelete();
            $table->decimal('requested_liters', 15, 2);
            $table->foreignId('registered_by_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('registered_at')->useCurrent();
            $table->bigInteger('version')->default(1);
            $table->index(['customer_id', 'requested_at', 'id']);
            $table->index('liquid_product_id');
            $table->index('route_id');
        });
        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transport_request_id')->constrained('transport_requests')->restrictOnDelete();
            $table->foreignUuid('liquid_product_id')->constrained('liquid_products')->restrictOnDelete();
            $table->text('product_name_snapshot');
            $table->text('planning_status')->default('scheduled');
            $table->text('execution_status')->default('pending');
            $table->text('administrative_status')->default('open');
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->timestampTz('scheduled_end_at')->nullable();
            $table->timestampTz('officially_started_at')->nullable();
            $table->timestampTz('officially_completed_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('started_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('registered_by_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('registered_at')->useCurrent();
            $table->bigInteger('version')->default(1);
            $table->index(['transport_request_id', 'id']);
            $table->index('liquid_product_id');
            $table->index(['execution_status', 'scheduled_start_at', 'id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE routes ADD CONSTRAINT transport_route_name CHECK (btrim(name) <> '');
            ALTER TABLE transport_requests ADD CONSTRAINT transport_request_volume CHECK (requested_liters > 0 AND requested_liters <> 'NaN'::numeric), ADD CONSTRAINT transport_request_version CHECK (version > 0);
            ALTER TABLE trips ADD CONSTRAINT transport_trip_version CHECK (version > 0),
                ADD CONSTRAINT transport_trip_product_name CHECK (btrim(product_name_snapshot) <> ''),
                ADD CONSTRAINT transport_trip_planning CHECK (planning_status IN ('scheduled','rescheduled')),
                ADD CONSTRAINT transport_trip_execution CHECK (execution_status IN ('pending','in_progress','completed','cancelled')),
                ADD CONSTRAINT transport_trip_administration CHECK (administrative_status IN ('open','closed')),
                ADD CONSTRAINT transport_trip_schedule CHECK (scheduled_start_at IS NULL OR scheduled_end_at IS NULL OR scheduled_end_at >= scheduled_start_at),
                ADD CONSTRAINT transport_trip_start_actor CHECK ((officially_started_at IS NULL) = (started_by_id IS NULL)),
                ADD CONSTRAINT transport_trip_end_actor CHECK ((officially_completed_at IS NULL) = (completed_by_id IS NULL)),
                ADD CONSTRAINT transport_trip_close_actor CHECK ((closed_at IS NULL) = (closed_by_id IS NULL)),
                ADD CONSTRAINT transport_trip_cancel_actor CHECK ((cancelled_at IS NULL) = (cancelled_by_id IS NULL)),
                ADD CONSTRAINT transport_trip_start_required CHECK (execution_status NOT IN ('in_progress','completed') OR officially_started_at IS NOT NULL),
                ADD CONSTRAINT transport_trip_end_required CHECK ((execution_status = 'completed') = (officially_completed_at IS NOT NULL)),
                ADD CONSTRAINT transport_trip_close_required CHECK ((administrative_status = 'closed') = (closed_at IS NOT NULL)),
                ADD CONSTRAINT transport_trip_close_after_completion CHECK (administrative_status <> 'closed' OR officially_completed_at IS NOT NULL),
                ADD CONSTRAINT transport_trip_cancel_required CHECK ((execution_status = 'cancelled') = (cancelled_at IS NOT NULL)),
                ADD CONSTRAINT transport_trip_cancel_reason CHECK ((execution_status = 'cancelled' AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '') OR (execution_status <> 'cancelled' AND cancellation_reason IS NULL)),
                ADD CONSTRAINT transport_trip_chronology CHECK ((officially_completed_at IS NULL OR officially_completed_at >= officially_started_at) AND (closed_at IS NULL OR closed_at >= officially_completed_at));
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
        Schema::dropIfExists('transport_requests');
        Schema::dropIfExists('routes');
    }
};
