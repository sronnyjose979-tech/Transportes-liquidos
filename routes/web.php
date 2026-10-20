<?php

use App\Modules\AccessAndPersonnel\Application\OfficePage;
use App\Modules\AccessAndPersonnel\Application\SystemNavigation;
use App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount;
use App\Modules\AccessAndPersonnel\Http\Middleware\EnsureSessionIdentity;
use App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::get('/driver-sw.js', fn () => response()->file(public_path('build/driver-sw.js'), ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-cache', 'Service-Worker-Allowed' => '/mis-viajes']));

Route::get('/login', fn () => response()->view('system-home', ['section' => 'login'])->header('Cache-Control', 'private, no-store'))->name('login');
Route::middleware(['auth', EnsureActiveAccount::class, 'auth.session', PrivatePage::class])->group(function () {
    Route::get('/', fn (Request $request, SystemNavigation $navigation) => redirect($navigation->home($request->user())));
    Route::get('/dashboard', fn (Request $request, OfficePage $page) => $page->render($request, 'dashboard'))->middleware([EnsureSessionIdentity::class, 'can:manage-personnel']);
    Route::get('/mis-viajes', fn () => view('system-home', ['section' => 'driver']))->middleware('can:view-assigned-trips');
    Route::get('/auditoria', fn (Request $request, OfficePage $page) => $page->render($request, 'audit'))->middleware([EnsureSessionIdentity::class, 'can:view-administrative-audit']);
    Route::get('/reportes', fn (Request $request, OfficePage $page) => $page->render($request, 'reports'))->middleware([EnsureSessionIdentity::class, 'can:view-administrative-reports']);
    Route::get('/alertas', fn (Request $request, OfficePage $page) => $page->render($request, 'alerts'))->middleware([EnsureSessionIdentity::class, 'can:manage-fleet-documents']);
    Route::get('/gastos', fn (Request $request, OfficePage $page) => $page->render($request, 'expenses'))->middleware([EnsureSessionIdentity::class, 'can:manage-expenses']);
    Route::get('/facturacion', fn (Request $request, OfficePage $page) => $page->render($request, 'billing'))->middleware([EnsureSessionIdentity::class, 'can:manage-billing']);
    Route::get('/taller', fn () => view('workshop'))->middleware('can:access-workshop')->name('workshop.web');
    Route::get('/documentacion', fn () => view('fleet-documents'))->middleware('can:manage-fleet-documents')->name('fleet.documents.web');
    Route::get('/transporte', fn (Request $request, OfficePage $page) => $page->render($request, 'transport'))->middleware([EnsureSessionIdentity::class, 'can:manage-transport-core'])->name('transport.web');
    Route::get('/inventario', fn (Request $request, OfficePage $page) => $page->render($request, 'inventory'))->middleware([EnsureSessionIdentity::class, 'can:access-inventory'])->name('inventory.web');
    Route::get('/compras', fn (Request $request, OfficePage $page) => $page->render($request, 'purchasing'))->middleware([EnsureSessionIdentity::class, 'can:access-purchasing'])->name('purchasing.web');
    Route::get('/administracion/{section}', function (Request $request, string $section, OfficePage $page) {
        Gate::authorize(match ($section) {
            'flota' => 'manage-fleet', 'personal' => 'manage-personnel', default => 'manage-catalogs',
        });

        return $page->render($request, ['flota' => 'fleet', 'personal' => 'personnel', 'catalogos' => 'catalogs', 'compras' => 'adminPurchasing'][$section]);
    })->middleware(EnsureSessionIdentity::class)->whereIn('section', ['flota', 'personal', 'catalogos', 'compras'])->name('administration.web');
});

require __DIR__.'/backend.php';
