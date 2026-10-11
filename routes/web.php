<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/documentacion', fn () => response()->view('fleet-documents')
    ->header('Cache-Control', 'no-store'))->name('fleet.documents.web');

require __DIR__.'/backend.php';
