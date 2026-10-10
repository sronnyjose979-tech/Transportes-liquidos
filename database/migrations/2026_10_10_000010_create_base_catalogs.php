<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
        });

        Schema::create('liquid_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->boolean('is_active');
        });

        Schema::create('operation_sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->text('address');
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
        });

        Schema::create('spare_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('code');
            $table->text('description');
            $table->text('unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spare_parts');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('operation_sites');
        Schema::dropIfExists('liquid_products');
        Schema::dropIfExists('customers');
    }
};
