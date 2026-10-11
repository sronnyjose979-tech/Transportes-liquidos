<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('trips')->where('execution_status', 'in_progress')->exists()) {
            throw new RuntimeException('Existen viajes en curso sin asignaciones verificables; resolver antes de migrar 5.2B.');
        }
        Schema::create('transport_assignment_guard', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->bigInteger('version');
        });
        DB::table('transport_assignment_guard')->insert(['id' => 1, 'version' => 1]);
        Schema::create('transport_assignment_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->text('action');
            $table->text('execution_status_snapshot')->default('pending');
            $table->text('resource_kind');
            $table->jsonb('payload');
            $table->bigInteger('target_version');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('registered_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->index(['trip_id', 'registered_at', 'id']);
        });
        Schema::create('trip_asset_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUuid('fleet_asset_id')->constrained('fleet_assets')->restrictOnDelete();
            $table->foreignUuid('operation_id')->constrained('transport_assignment_operations')->restrictOnDelete();
            $table->unique('operation_id');
            $table->timestampTz('starts_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->text('asset_type');
            $table->index(['trip_id', 'starts_at']);
            $table->index(['fleet_asset_id', 'starts_at']);
        });
        Schema::create('trip_asset_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assignment_id')->constrained('trip_asset_assignments')->restrictOnDelete();
            $table->unique('assignment_id');
            $table->foreignUuid('operation_id')->constrained('transport_assignment_operations')->restrictOnDelete();
            $table->unique('operation_id');
            $table->timestampTz('ends_at', 6)->default(DB::raw('clock_timestamp()'));
        });
        Schema::create('driver_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('operation_id')->constrained('transport_assignment_operations')->restrictOnDelete();
            $table->unique('operation_id');
            $table->timestampTz('starts_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->index(['trip_id', 'starts_at']);
            $table->index(['employee_id', 'starts_at']);
        });
        Schema::create('driver_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assignment_id')->constrained('driver_assignments')->restrictOnDelete();
            $table->unique('assignment_id');
            $table->foreignUuid('operation_id')->constrained('transport_assignment_operations')->restrictOnDelete();
            $table->unique('operation_id');
            $table->timestampTz('ends_at', 6)->default(DB::raw('clock_timestamp()'));
        });
        Schema::create('transport_assignment_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operation_id')->constrained('transport_assignment_operations')->restrictOnDelete();
            $table->foreignUuid('asset_assignment_id')->nullable()->constrained('trip_asset_assignments')->restrictOnDelete();
            $table->foreignUuid('driver_assignment_id')->nullable()->constrained('driver_assignments')->restrictOnDelete();
            $table->unique(['operation_id', 'asset_assignment_id']);
            $table->unique(['operation_id', 'driver_assignment_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE transport_assignment_guard ADD CONSTRAINT assignment_guard_identity CHECK (id=1 AND version>0);
            ALTER TABLE transport_assignment_operations ADD CONSTRAINT assignment_operation_action CHECK (action IN ('assign','release','substitute','conflict_detected')),
                ADD CONSTRAINT assignment_operation_kind CHECK (resource_kind IN ('driver','tractor_unit','tanker')),
                ADD CONSTRAINT assignment_operation_content CHECK (jsonb_typeof(payload)='object' AND btrim(reason)<>'' AND target_version>0);
            ALTER TABLE trip_asset_assignments ADD CONSTRAINT assignment_asset_type CHECK (asset_type IN ('tractor_unit','tanker'));
            ALTER TABLE transport_assignment_conflicts ADD CONSTRAINT assignment_conflict_xor CHECK (num_nonnulls(asset_assignment_id,driver_assignment_id)=1);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_assignment_conflicts');
        Schema::dropIfExists('driver_releases');
        Schema::dropIfExists('driver_assignments');
        Schema::dropIfExists('trip_asset_releases');
        Schema::dropIfExists('trip_asset_assignments');
        Schema::dropIfExists('transport_assignment_operations');
        Schema::dropIfExists('transport_assignment_guard');
    }
};
