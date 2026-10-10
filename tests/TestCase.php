<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        if (is_file(__DIR__.'/../bootstrap/cache/config.php')) {
            throw new \RuntimeException('No ejecutar pruebas con configuración cacheada.');
        }
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.pgsql_testing');
        if (! is_array($connection) || $connection['database'] !== 'transporte_test' || $connection['url'] !== null) {
            throw new \RuntimeException('Conexión de pruebas inválida.');
        }
        // No named secondary connection may fall back to the real environment.
        $app['config']->set('database.connections', ['pgsql_testing' => $connection]);
        $app['config']->set('database.default', 'pgsql_testing');

        return $app;
    }
}
