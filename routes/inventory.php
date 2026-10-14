<?php

use App\Modules\Inventory\Http\Controllers\InventoryController;
use App\Modules\Inventory\Http\Middleware\InventoryApiErrors;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory')->middleware([InventoryApiErrors::class, 'can:access-inventory'])->group(function () {
    Route::get('stock', [InventoryController::class, 'stock']);
    Route::get('movements', [InventoryController::class, 'movements']);
    Route::get('custody', [InventoryController::class, 'custody']);
    Route::get('corrections', [InventoryController::class, 'corrections']);
    Route::post('corrections', [InventoryController::class, 'store'])->name('inventory.corrections.store');
    Route::post('corrections/{id}/decisions', [InventoryController::class, 'decide'])->whereUuid('id')->name('inventory.decisions.store');
    Route::post('decisions/{id}/apply', [InventoryController::class, 'apply'])->whereUuid('id')->name('inventory.decisions.apply');
    Route::post('returns', [InventoryController::class, 'returns'])->name('inventory.returns.store');
});
