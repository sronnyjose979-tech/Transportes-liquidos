<?php

use Illuminate\Contracts\Http\Kernel;
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
if ((str_starts_with($uri, '/build/') || in_array($uri, ['/driver.webmanifest', '/driver-icon-192.png', '/driver-icon-512.png'], true)) && ! str_contains(rawurldecode($uri), '..')
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
// Isolated UI-contract harness renders only the real Blade shells. It intentionally
// bypasses page authorization here, because API/session responses are intercepted
// and no database exists. Server authorization is tested separately on PostgreSQL.
$app->instance('request', Request::capture());
$app->make(Kernel::class)->bootstrap();
$pages = ['/documentacion' => ['fleet-documents', []], '/transporte' => ['transport', []], '/taller' => ['workshop', []],
    '/inventario' => ['supply', ['section' => 'inventory']], '/compras' => ['supply', ['section' => 'purchasing']],
    '/auditoria' => ['system-home', ['section' => 'audit']],
    '/alertas' => ['system-home', ['section' => 'alerts']], '/reportes' => ['system-home', ['section' => 'reports']], '/gastos' => ['system-home', ['section' => 'expenses']], '/login' => ['system-home', ['section' => 'login']], '/dashboard' => ['system-home', ['section' => 'dashboard']],
    '/mis-viajes' => ['system-home', ['section' => 'driver']], '/facturacion' => ['system-home', ['section' => 'billing']]];
foreach (['flota', 'personal', 'catalogos', 'compras'] as $section) {
    $pages['/administracion/'.$section] = ['administration', compact('section')];
}
if (isset($pages[$uri])) {
    [$view, $data] = $pages[$uri];
    if (getenv('TRANSPORTE_BROWSER_OFFICE') === '1' && array_key_exists($uri, ['/dashboard' => 'dashboard', '/transporte' => 'transport', '/administracion/flota' => 'fleet', '/administracion/personal' => 'personnel', '/administracion/catalogos' => 'catalogs', '/administracion/compras' => 'adminPurchasing', '/auditoria' => 'audit', '/reportes' => 'reports'])) {
        // Synthetic bootstrap only; transition and data requests are intercepted by Playwright.
        $role = ($_GET['office_role'] ?? '') === 'secretary' ? 'secretary' : 'administrator';
        $modules = [
            ['key' => 'dashboard', 'label' => 'Inicio', 'href' => '/dashboard'],
            ['key' => 'transport', 'label' => 'Transporte', 'href' => '/transporte'],
            ['key' => 'fleet', 'label' => 'Flota', 'href' => '/administracion/flota'],
            ['key' => 'driver', 'label' => 'Mis viajes', 'href' => '/mis-viajes'],
            ['key' => 'personnel', 'label' => 'Personal', 'href' => '/administracion/personal'],
        ];
        if ($role === 'secretary') {
            $modules = [$modules[1]];
        }
        $view = 'office';
        $data = ['context' => ['page' => ['/dashboard' => 'dashboard', '/transporte' => 'transport', '/administracion/flota' => 'fleet', '/administracion/personal' => 'personnel', '/administracion/catalogos' => 'catalogs', '/administracion/compras' => 'adminPurchasing', '/auditoria' => 'audit', '/reportes' => 'reports'][$uri],
            'session' => ['id' => '7', 'login' => $role, 'roles' => [$role]],
            'navigation' => ['home' => $role === 'secretary' ? '/transporte' : '/dashboard', 'modules' => $modules]]];
    }
    response()->view($view, $data)->header('Cache-Control', 'private, no-store')->header('Set-Cookie', 'XSRF-TOKEN=browser-contract-only; Path=/; SameSite=Lax')->send();
} else {
    $app->handleRequest(Request::capture());
}
