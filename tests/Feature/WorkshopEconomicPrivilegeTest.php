<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class WorkshopEconomicPrivilegeTest extends PostgresTestCase
{
    public function test_restricted_writer_must_use_validated_decimal_functions(): void
    {
        $role = 'workshop_economic_test_'.str_replace('-', '', (string) Str::uuid());
        DB::statement("CREATE ROLE {$role} NOLOGIN NOSUPERUSER");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");
        $writers = ['delivery' => 'workshop_part_deliveries', 'usage' => 'workshop_part_usages', 'material_need' => 'workshop_material_needs', 'cost' => 'workshop_cost_records'];
        foreach ($writers as $writer => $table) {
            try {
                DB::statement("SET LOCAL ROLE {$role}");
                $this->rejected(fn () => DB::table($table)->insert(['id' => (string) Str::uuid()]), '42501');
                $call = fn (string $decimal) => DB::select("SELECT public.workshop_write_{$writer}(?::jsonb,?::text)", ['{}', $decimal]);
                $this->rejected(fn () => $call('1'), '42501');
                DB::statement('RESET ROLE');
                DB::statement("GRANT EXECUTE ON FUNCTION public.workshop_write_{$writer}(jsonb,text) TO {$role}");
                DB::statement("SET LOCAL ROLE {$role}");
                foreach (['1.0000000', 'NaN', 'Infinity', '1e2', '1,25', '-1'] as $decimal) {
                    $this->rejected(fn () => $call($decimal), '23514');
                }
            } finally {
                DB::statement('RESET ROLE');
            }
        }
    }

    private function rejected(callable $operation, string $state): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Escritura no permitida aceptada.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
