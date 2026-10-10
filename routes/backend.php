<?php

use App\Modules\AccessAndPersonnel\Http\Controllers\AccountController;
use App\Modules\AccessAndPersonnel\Http\Controllers\EmployeeController;
use App\Modules\AccessAndPersonnel\Http\Controllers\SessionController;
use App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount;
use App\Modules\Inventory\Http\Controllers\SparePartController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\TransportAndDispatch\Http\Controllers\CustomerController;
use App\Modules\TransportAndDispatch\Http\Controllers\LiquidProductController;
use App\Modules\TransportAndDispatch\Http\Controllers\OperationSiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('csrf', fn () => response()->noContent()->header('Cache-Control', 'no-store'));
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::middleware(['auth', EnsureActiveAccount::class, 'auth.session'])->group(function () {
        Route::get('session', [SessionController::class, 'show']);
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::middleware('can:manage-personnel')->group(function () {
            $accounts = AccountController::class;
            $employees = EmployeeController::class;
            Route::get('roles', [$accounts, 'roles']);
            Route::get('users', [$accounts, 'index']);
            Route::post('users', [$accounts, 'store']);
            Route::get('users/{id}', [$accounts, 'show'])->whereNumber('id');
            Route::patch('users/{id}', [$accounts, 'update'])->whereNumber('id');
            Route::patch('users/{id}/activation', [$accounts, 'update'])->whereNumber('id')->name('users.activation');
            Route::get('employees', [$employees, 'index']);
            Route::post('employees', [$employees, 'store']);
            Route::get('employees/{id}', [$employees, 'show'])->whereUuid('id');
            Route::patch('employees/{id}', [$employees, 'update'])->whereUuid('id');
        });
        Route::middleware('can:manage-catalogs')->group(function () {
            foreach (['customers' => CustomerController::class, 'liquid-products' => LiquidProductController::class, 'operation-sites' => OperationSiteController::class, 'suppliers' => SupplierController::class, 'spare-parts' => SparePartController::class] as $catalog => $controller) {
                Route::get('catalogs/'.$catalog, [$controller, 'index']);
                Route::post('catalogs/'.$catalog, [$controller, 'store']);
                Route::get('catalogs/'.$catalog.'/{id}', [$controller, 'show'])->whereUuid('id');
                Route::put('catalogs/'.$catalog.'/{id}', [$controller, 'update'])->whereUuid('id');
            }
        });
    });
});
