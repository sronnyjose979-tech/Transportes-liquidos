<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->view('workshop')->header('Cache-Control', 'no-store');
});

Route::get('/taller', fn () => response()->view('workshop')->header('Cache-Control', 'no-store'))->name('workshop.web');

Route::get('/documentacion', fn () => response()->view('fleet-documents')
    ->header('Cache-Control', 'no-store'))->name('fleet.documents.web');

Route::get('/transporte', fn () => response()->view('transport')->header('Cache-Control', 'no-store'))->name('transport.web');

require __DIR__.'/backend.php';
