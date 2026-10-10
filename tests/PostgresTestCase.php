<?php

namespace Tests;

use Illuminate\Support\Facades\DB;

abstract class PostgresTestCase extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('database.connections.pgsql_testing');
        if (getenv('TEST_DB_ISOLATED') !== '1' || ! str_starts_with($config['host'], '/tmp/transporte-tests-') || $config['username'] !== 'transport_test') {
            throw new \RuntimeException('Requiere PostgreSQL temporal aislado y TEST_DB_ISOLATED=1.');
        }
        $identity = DB::selectOne("SELECT current_database() AS db, current_user AS actor, current_setting('server_version_num')::integer AS version");
        if ($identity->db !== 'transporte_test' || $identity->actor !== 'transport_test' || intdiv($identity->version, 10000) !== 18) {
            throw new \RuntimeException('Identidad del servidor de pruebas inválida.');
        }
        if (! self::$migrated) {
            $this->artisan('migrate', ['--database' => 'pgsql_testing', '--force' => true])->assertExitCode(0);
            self::$migrated = true;
        }
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->app) && $this->app && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }
}
