<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

$workspace = getenv('TRANSPORTE_BROWSER_ROOT');
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'testing'
    || ! is_string($workspace) || ! str_starts_with($workspace, '/tmp/transporte-web-tests-')
    || realpath($workspace) !== $workspace || ! is_dir($workspace)) {
    throw new RuntimeException('Servidor de navegador requiere un directorio temporal aislado.');
}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
if (str_starts_with($uri, '/build/') && ! str_contains(rawurldecode($uri), '..')
    && is_file(__DIR__.'/../../public'.$uri)) {
    return false;
}
define('LARAVEL_START', microtime(true));
require_once __DIR__.'/../../vendor/autoload.php';
foreach (['config', 'routes', 'events'] as $cache) {
    $variable = 'APP_'.strtoupper($cache).'_CACHE';
    $_ENV[$variable] = $_SERVER[$variable] = $workspace.'/'.$cache.'.php';
    putenv($variable.'='.$workspace.'/'.$cache.'.php');
}
/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useEnvironmentPath($workspace)->loadEnvironmentFrom('absent-browser.env');
$app->useStoragePath($workspace.'/storage');
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($workspace): void {
    $app['config']->set([
        'app.env' => 'testing', 'app.debug' => false, 'app.key' => getenv('TRANSPORTE_BROWSER_KEY'),
        'database.default' => 'pgsql_testing',
        'database.connections' => ['pgsql_testing' => [
            'driver' => 'pgsql', 'host' => $workspace.'/no-database', 'port' => 5432,
            'database' => 'browser_unavailable', 'username' => 'browser_unavailable', 'password' => '',
        ]],
        'session.driver' => 'array', 'session.secure' => false, 'session.domain' => null,
        'cache.default' => 'array', 'queue.default' => 'sync',
        'view.compiled' => $workspace.'/storage/framework/views',
        'logging.default' => 'single', 'logging.channels.single.path' => $workspace.'/laravel.log',
    ]);
});
$app->afterBootstrapping(BootProviders::class, function () use ($workspace): void {
    Vite::useHotFile($workspace.'/absent.hot');
});
$app->handleRequest(Request::capture());
