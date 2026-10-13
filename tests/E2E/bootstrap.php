<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../vendor/autoload.php';

$workspace = getenv('TRANSPORTE_E2E_ROOT');
$host = getenv('TEST_DB_HOST');
if (getenv('TEST_DB_ISOLATED') !== '1' || getenv('APP_ENV') !== 'e2e'
    || ! is_string($host) || ! str_starts_with($host, '/tmp/transporte-tests-')
    || realpath($host) !== $host || ! is_dir($host.'/data')
    || $workspace !== $host.'/e2e' || ! is_dir($workspace)
    || getenv('TEST_DB_USERNAME') !== 'transport_test') {
    throw new RuntimeException('E2E requiere el clúster temporal creado por tests/run-postgres.sh --e2e.');
}
foreach (['config', 'routes', 'events'] as $cache) {
    $variable = 'APP_'.strtoupper($cache).'_CACHE';
    $_ENV[$variable] = $_SERVER[$variable] = $workspace.'/'.$cache.'.php';
    putenv($variable.'='.$workspace.'/'.$cache.'.php');
}
/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
// Never load the development .env, its cached configuration, or its storage.
$app->useEnvironmentPath($workspace)->loadEnvironmentFrom('absent-test.env');
$app->useStoragePath($workspace.'/storage');
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($host, $workspace): void {
    $config = $app['config'];
    $connection = $config->get('database.connections.pgsql_testing');
    if ($connection['url'] !== null || $connection['database'] !== 'transporte_test'
        || $connection['username'] !== 'transport_test' || $connection['host'] !== $host) {
        throw new RuntimeException('Configuración E2E no aislada.');
    }
    $key = getenv('TRANSPORTE_E2E_KEY');
    if (! is_string($key) || ! str_starts_with($key, 'base64:')) {
        throw new RuntimeException('Falta clave efímera E2E.');
    }
    $config->set([
        'app.env' => 'e2e', 'app.debug' => false, 'app.key' => $key, 'app.url' => 'http://127.0.0.1',
        'database.default' => 'pgsql_testing', 'database.connections' => ['pgsql_testing' => $connection],
        'session.driver' => 'database', 'session.connection' => 'pgsql_testing',
        'session.cookie' => 'transporte_e2e_session', 'session.secure' => false, 'session.domain' => null,
        'session.lifetime' => 120, 'cache.default' => 'file', 'queue.default' => 'sync',
        'view.compiled' => $workspace.'/storage/framework/views',
        'logging.default' => 'single', 'logging.channels.single.path' => $workspace.'/laravel.log',
    ]);
});
$app->afterBootstrapping(BootProviders::class, function () use ($workspace): void {
    app(Vite::class)->useHotFile($workspace.'/absent-vite.hot');
    $identity = DB::selectOne("SELECT current_database() AS db, current_user AS actor, current_setting('server_version_num')::integer AS version");
    if ($identity->db !== 'transporte_test' || $identity->actor !== 'transport_test' || intdiv($identity->version, 10000) !== 18) {
        throw new RuntimeException('Identidad PostgreSQL E2E inválida.');
    }
    if (is_file($workspace.'/clock')) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(trim(file_get_contents($workspace.'/clock'))));
    }
});

return $app;
