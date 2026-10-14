<?php

use App\Modules\AccessAndPersonnel\Http\Middleware\PrivatePage;
use App\Modules\Expenses\Http\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::prefix('expenses')->middleware([PrivatePage::class, 'can:access-expenses'])->name('expenses.')->group(function () {
    Route::get('/', [ExpenseController::class, 'index']);
    Route::post('/', [ExpenseController::class, 'store'])->name('store');
    Route::get('receipts/{receipt}/download', [ExpenseController::class, 'download'])->whereUuid('receipt');
    Route::get('{id}', [ExpenseController::class, 'show'])->whereUuid('id');
    Route::post('{id}/corrections', [ExpenseController::class, 'correct'])->whereUuid('id')->middleware('can:manage-expenses')->name('correct');
    Route::post('{id}/decisions', [ExpenseController::class, 'decide'])->whereUuid('id')->middleware('can:approve-expenses')->name('decide');
    Route::post('{id}/receipts', [ExpenseController::class, 'upload'])->whereUuid('id')->name('receipts.store');
});
