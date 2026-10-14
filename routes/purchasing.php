<?php

use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\PurchaseRequestController;
use App\Modules\Purchasing\Http\Middleware\PurchasingApiErrors;
use Illuminate\Support\Facades\Route;

Route::prefix('purchasing')->middleware([PurchasingApiErrors::class, 'can:access-purchasing'])->group(function () {
    Route::get('requests', [PurchaseRequestController::class, 'index']);
    Route::post('requests', [PurchaseRequestController::class, 'store'])->middleware('can:manage-purchase-requests');
    Route::get('requests/{id}', [PurchaseRequestController::class, 'show'])->whereUuid('id');
    Route::post('requests/{id}/decisions', [PurchaseRequestController::class, 'decide'])->whereUuid('id')->middleware('can:decide-purchase-request');
    Route::get('lookups', [PurchaseOrderController::class, 'lookups']);
    Route::get('orders', [PurchaseOrderController::class, 'index']);
    Route::post('orders', [PurchaseOrderController::class, 'store']);
    Route::get('orders/{id}', [PurchaseOrderController::class, 'show'])->whereUuid('id');
    Route::post('orders/{id}/receipts', [PurchaseOrderController::class, 'receive'])->whereUuid('id');
});
