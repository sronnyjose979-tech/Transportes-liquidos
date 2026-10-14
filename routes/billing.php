<?php

use App\Modules\BillingAndPayments\Http\Controllers\BillingController;
use App\Modules\BillingAndPayments\Http\Controllers\BillingReviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('billing')->middleware('can:manage-billing')->name('billing.')->group(function () {
    Route::get('trips', [BillingReviewController::class, 'index']);
    Route::post('trips/{trip}/amounts', [BillingController::class, 'propose'])->whereUuid('trip')->name('amounts.store');
    Route::post('amounts/{id}/decisions', [BillingController::class, 'decide'])->whereUuid('id')->middleware('can:approve-trip-amount')->name('amounts.decide');
    Route::get('invoices', [BillingController::class, 'index']);
    Route::post('invoices', [BillingController::class, 'store'])->name('invoices.store');
    Route::get('invoices/{id}', [BillingController::class, 'show'])->whereUuid('id');
    Route::post('invoices/{id}/payments', [BillingController::class, 'pay'])->whereUuid('id')->name('payments.store');
});
