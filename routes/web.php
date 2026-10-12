<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/documentacion', fn () => response()->view('fleet-documents')
    ->header('Cache-Control', 'no-store'))->name('fleet.documents.web');

Route::get('/transporte', fn () => response()->view('transport')->header('Cache-Control', 'no-store'))->name('transport.web');

require __DIR__.'/backend.php';
