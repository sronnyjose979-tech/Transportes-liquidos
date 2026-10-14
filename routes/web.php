<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->view('workshop')->header('Cache-Control', 'no-store');
});

Route::get('/taller', fn () => response()->view('workshop')->header('Cache-Control', 'no-store'))->name('workshop.web');

Route::get('/documentacion', fn () => response()->view('fleet-documents')
    ->header('Cache-Control', 'no-store'))->name('fleet.documents.web');

Route::get('/transporte', fn () => response()->view('transport')->header('Cache-Control', 'no-store'))->name('transport.web');

Route::get('/inventario', fn () => response()->view('supply', ['section' => 'inventory'])->header('Cache-Control', 'no-store'))->name('inventory.web');
Route::get('/compras', fn () => response()->view('supply', ['section' => 'purchasing'])->header('Cache-Control', 'no-store'))->name('purchasing.web');

Route::get('/administracion/{section}', fn (string $section) => response()->view('administration', compact('section'))
    ->header('Cache-Control', 'no-store'))->whereIn('section', ['flota', 'personal', 'catalogos', 'compras'])->name('administration.web');

require __DIR__.'/backend.php';
