<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->text('type');
            $table->jsonb('information');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('registered_at')->useCurrent();
            $table->index(['trip_id', 'registered_at', 'id']);
        });
        Schema::create('trip_warnings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->text('code');
            $table->jsonb('evidence');
            $table->timestampTz('captured_at')->useCurrent();
            $table->bigInteger('closure_version');
            $table->index(['trip_id', 'captured_at', 'id']);
        });
        Schema::create('trip_warning_resolutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('warning_id')->constrained('trip_warnings')->restrictOnDelete();
            $table->text('action');
            $table->jsonb('evidence');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('registered_at')->useCurrent();
            $table->index(['warning_id', 'registered_at', 'id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE trip_events ADD CONSTRAINT transport_event_type CHECK (type IN ('information','completion_report','incident')), ADD CONSTRAINT transport_event_information CHECK (jsonb_typeof(information)='object');
            ALTER TABLE trip_warnings ADD CONSTRAINT transport_warning_content CHECK (btrim(code)<>'' AND jsonb_typeof(evidence)='object' AND closure_version>0);
            ALTER TABLE trip_warning_resolutions ADD CONSTRAINT transport_resolution_content CHECK (btrim(action)<>'' AND btrim(reason)<>'' AND jsonb_typeof(evidence)='object');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_warning_resolutions');
        Schema::dropIfExists('trip_warnings');
        Schema::dropIfExists('trip_events');
    }
};
