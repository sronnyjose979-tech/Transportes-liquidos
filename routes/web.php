<?php

use App\Modules\AccessAndPersonnel\Application\SystemNavigation;
use App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount;
use App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::get('/login', fn () => response()->view('system-home', ['section' => 'login'])->header('Cache-Control', 'private, no-store'))->name('login');
Route::middleware(['auth', EnsureActiveAccount::class, 'auth.session', PrivatePage::class])->group(function () {
    Route::get('/', fn (Request $request, SystemNavigation $navigation) => redirect($navigation->home($request->user())));
    Route::get('/dashboard', fn () => view('system-home', ['section' => 'dashboard']))->middleware('can:manage-personnel');
    Route::get('/mis-viajes', fn () => view('system-home', ['section' => 'driver']))->middleware('can:view-assigned-trips');
    Route::get('/facturacion', fn () => view('system-home', ['section' => 'billing']))->middleware('can:manage-transport-core');
    Route::get('/taller', fn () => view('workshop'))->middleware('can:access-workshop')->name('workshop.web');
    Route::get('/documentacion', fn () => view('fleet-documents'))->middleware('can:manage-fleet-documents')->name('fleet.documents.web');
    Route::get('/transporte', fn () => view('transport'))->middleware('can:manage-transport-core')->name('transport.web');
    Route::get('/inventario', fn () => view('supply', ['section' => 'inventory']))->middleware('can:access-inventory')->name('inventory.web');
    Route::get('/compras', fn () => view('supply', ['section' => 'purchasing']))->middleware('can:access-purchasing')->name('purchasing.web');
    Route::get('/administracion/{section}', function (string $section) {
        Gate::authorize(match ($section) {
            'flota' => 'manage-fleet', 'personal' => 'manage-personnel', default => 'manage-catalogs',
        });

        return view('administration', compact('section'));
    })->whereIn('section', ['flota', 'personal', 'catalogos', 'compras'])->name('administration.web');
});

require __DIR__.'/backend.php';
