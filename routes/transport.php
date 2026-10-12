<?php

use App\Modules\TransportAndDispatch\Http\Controllers\TransportAuthorizationController;
use App\Modules\TransportAndDispatch\Http\Controllers\TransportLookupController;
use App\Modules\TransportAndDispatch\Http\Controllers\TransportRequestController;
use App\Modules\TransportAndDispatch\Http\Controllers\TripAssignmentController;
use App\Modules\TransportAndDispatch\Http\Controllers\TripController;
use App\Modules\TransportAndDispatch\Http\Controllers\TripOperationController;
use App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors;
use Illuminate\Support\Facades\Route;

Route::prefix('transport')->name('transport.')->middleware(TransportApiErrors::class)->group(function () {
    Route::post('trips/{id}/reports', [TripOperationController::class, 'report'])->whereUuid('id')->name('operations.report');
    Route::middleware('can:manage-transport-core')->group(function () {
        Route::get('lookups/{kind}', [TransportLookupController::class, 'index']);
        Route::get('requests', [TransportRequestController::class, 'index']);
        Route::post('requests', [TransportRequestController::class, 'store']);
        Route::get('requests/{id}', [TransportRequestController::class, 'show'])->whereUuid('id');
        Route::patch('requests/{id}', [TransportRequestController::class, 'update'])->whereUuid('id');
        Route::get('requests/{id}/history', [TransportRequestController::class, 'history'])->whereUuid('id');
        Route::get('trips', [TripController::class, 'index']);
        Route::post('trips', [TripController::class, 'store']);
        Route::get('availability', [TripAssignmentController::class, 'availability']);
        Route::get('authorizations/{id}', [TransportAuthorizationController::class, 'show'])->whereUuid('id');
        Route::post('authorizations/{id}/decisions', [TransportAuthorizationController::class, 'decide'])->whereUuid('id')->name('authorizations.decide')->middleware('can:decide-transport-authorization');
        Route::prefix('trips/{id}')->whereUuid('id')->group(function () {
            Route::get('/', [TripController::class, 'show']);
            Route::get('history', [TripController::class, 'history']);
            Route::get('warnings', [TripController::class, 'warnings']);
            foreach (['schedule', 'reschedule', 'start', 'finish', 'cancel', 'close'] as $action) {
                Route::post($action, [TripController::class, 'action'])->name('trips.'.$action);
            }
            Route::get('assignments', [TripAssignmentController::class, 'history']);
            foreach (['assign', 'substitute', 'release'] as $action) {
                Route::post('assignments/'.$action, [TripAssignmentController::class, 'write'])->name('assignments.'.$action);
            }
            Route::get('authorizations', [TransportAuthorizationController::class, 'index']);
            Route::post('authorizations', [TransportAuthorizationController::class, 'store']);
            Route::get('load', [TripOperationController::class, 'show'])->name('operations.show-load');
            Route::post('load', [TripOperationController::class, 'store'])->name('operations.load');
            Route::get('unload', [TripOperationController::class, 'show'])->name('operations.show-unload');
            Route::post('unload', [TripOperationController::class, 'store'])->name('operations.unload');
            Route::get('reports', [TripOperationController::class, 'reports']);
            Route::get('odometer', [TripOperationController::class, 'readings']);
            Route::post('odometer', [TripOperationController::class, 'reading']);
            Route::post('odometer/{readingId}/corrections', [TripOperationController::class, 'correct'])->whereUuid('readingId')->name('odometer.correct');
            Route::get('distance', [TripOperationController::class, 'distance']);
            Route::get('volume-differences',[TripOperationController::class, 'differences']);
        });
    });
});
