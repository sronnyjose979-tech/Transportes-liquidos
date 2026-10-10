<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('login')->nullable()->unique();
            // Existing accounts require explicit activation; never grant access by migration.
            $table->boolean('is_active')->default(false);
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
        });
        Schema::create('user_roles', function (Blueprint $table) {
            // Compatibility with existing users; UUID conversion requires a separate data plan.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->text('name');
            $table->boolean('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login', 'is_active']);
        });
    }
};
