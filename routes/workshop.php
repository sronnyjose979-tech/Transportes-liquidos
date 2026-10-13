<?php

use App\Modules\Workshop\Http\Controllers\WorkshopQueryController;
use App\Modules\Workshop\Http\Middleware\WorkshopApiErrors;
use Illuminate\Support\Facades\Route;

Route::prefix('workshop')->middleware([WorkshopApiErrors::class, 'can:access-workshop'])->group(function () {
    require __DIR__.'/workshop-core.php';
    require __DIR__.'/workshop-process.php';
    require __DIR__.'/workshop-materials.php';
    foreach (array_keys(WorkshopQueryController::RECORDS) as $kind) {
        Route::get($kind, [WorkshopQueryController::class, 'index'])->defaults('kind', $kind)->name('workshop.query.'.$kind.'.index');
        Route::get($kind.'/{id}', [WorkshopQueryController::class, 'show'])->whereUuid('id')->defaults('kind', $kind)->name('workshop.query.'.$kind.'.show');
    }
    foreach (['diagnoses', 'mechanic-assignments', 'report-links', 'admission-links', 'scopes', 'authorization-requests', 'activities', 'time-events', 'deliveries', 'usages', 'material-needs', 'external-repairs', 'costs'] as $kind) {
        Route::get('orders/{order}/'.$kind, [WorkshopQueryController::class, 'forOrder'])->whereUuid('order')->defaults('kind', $kind)->name('workshop.query.order.'.$kind);
    }
    Route::get('assets/{asset}/availability', [WorkshopQueryController::class, 'asset'])->whereUuid('asset')->name('workshop.query.availability');
});
