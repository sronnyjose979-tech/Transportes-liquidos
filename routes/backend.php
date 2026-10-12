<?php

use App\Modules\AccessAndPersonnel\Http\Controllers\AccountController;
use App\Modules\AccessAndPersonnel\Http\Controllers\EmployeeController;
use App\Modules\AccessAndPersonnel\Http\Controllers\SessionController;
use App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount;
use App\Modules\FleetAndDocuments\Http\Controllers\DocumentController;
use App\Modules\FleetAndDocuments\Http\Controllers\DocumentTypeController;
use App\Modules\FleetAndDocuments\Http\Controllers\FleetAssetLookupController;
use App\Modules\FleetAndDocuments\Http\Controllers\TankerController;
use App\Modules\FleetAndDocuments\Http\Controllers\TractorUnitController;
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
        require __DIR__.'/transport.php';
        Route::get('session', [SessionController::class, 'show']);
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::middleware('can:manage-fleet-documents')->name('fleet.documents.')->group(function () {
            Route::get('fleet-assets', [FleetAssetLookupController::class, 'index'])->name('assets');
            $documents = DocumentController::class;
            $types = DocumentTypeController::class;
            Route::get('document-types', [$types, 'index']);
            Route::post('document-types', [$types, 'store'])->middleware('can:manage-document-types');
            Route::patch('document-types/{id}', [$types, 'update'])->whereUuid('id')->middleware('can:manage-document-types');
            Route::get('documents', [$documents, 'index'])->name('index');
            Route::get('documents/{id}', [$documents, 'show'])->whereUuid('id')->name('show');
            Route::get('documents/{id}/download', [$documents, 'download'])->whereUuid('id')->name('download');
            Route::get('fleet-assets/{assetId}/documents', [$documents, 'index'])->whereUuid('assetId')->name('asset');
            Route::get('fleet-assets/{assetId}/documents/history', [$documents, 'index'])->whereUuid('assetId')->name('history');
            Route::get('fleet-assets/{assetId}/documents/missing', [$documents, 'missing'])->whereUuid('assetId')->name('missing');
            Route::post('fleet-assets/{assetId}/documents', [$documents, 'store'])->whereUuid('assetId')->name('store');
            Route::post('fleet-assets/{assetId}/documents/{id}/renew', [$documents, 'renew'])->whereUuid(['assetId', 'id'])->name('renew');
        });
        Route::middleware('can:manage-fleet')->name('fleet.')->group(function () {
            foreach (['tractor-units' => TractorUnitController::class, 'tankers' => TankerController::class] as $units => $controller) {
                Route::get($units, [$controller, 'index'])->name($units.'.index');
                Route::post($units, [$controller, 'store'])->name($units.'.store');
                Route::get($units.'/{id}', [$controller, 'show'])->whereUuid('id')->name($units.'.show');
                Route::patch($units.'/{id}', [$controller, 'update'])->whereUuid('id')->name($units.'.update');
            }
        });
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
