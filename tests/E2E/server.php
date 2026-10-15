<?php

use Illuminate\Http\Request;

// Only the isolated runner may start this router. It is outside the public root.
if (PHP_SAPI !== 'cli-server' || getenv('TEST_DB_ISOLATED') !== '1') {
    throw new RuntimeException('Servidor E2E no autorizado.');
}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
// Serve compiled public assets only. All application routes run the real Laravel kernel.
if ((str_starts_with($uri, '/build/') || in_array($uri, ['/driver.webmanifest', '/driver-icon-192.png', '/driver-icon-512.png'], true)) && ! str_contains(rawurldecode($uri), '..')
    && is_file(__DIR__.'/../../public'.$uri)) {
    return false;
}
define('LARAVEL_START', microtime(true));
$app = require __DIR__.'/bootstrap.php';
$app->handleRequest(Request::capture());
