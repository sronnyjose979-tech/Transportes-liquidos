<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ADMIN = "r.name='administrator'";

    private const OFFICE = "(r.name='administrator' OR (r.name='secretary' AND (TG_TABLE_NAME='purchase_requests' OR (TG_TABLE_NAME='purchasing_operations' AND to_jsonb(NEW)->>'scope'='request_create'))))";

    private function replace(string $from, string $to): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('purchasing_validate()'::regprocedure) AS definition")->definition;
        if (substr_count($definition, $from) !== 1) {
            throw new RuntimeException('La autorización SQL de Compras cambió; revisar antes de ampliar permisos.');
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }

    public function up(): void
    {
        $this->replace(self::ADMIN, self::OFFICE);
    }

    public function down(): void
    {
        $this->replace(self::OFFICE, self::ADMIN);
    }
};
