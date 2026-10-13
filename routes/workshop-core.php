<?php

use App\Modules\Workshop\Http\Controllers\WorkshopCoreController;
use Illuminate\Support\Facades\Route;

foreach ([
    ['post', 'orders', 'order-create'],
    ['patch', 'orders/{order}', 'order-update'],
    ['post', 'reports', 'report-create'],
    ['post', 'reports/{report}/revisions', 'report-revise'],
    ['post', 'orders/{order}/report-links', 'report-link'],
    ['post', 'report-links/{link}/invalidate', 'report-link-invalidate'],
    ['post', 'orders/{order}/mechanic-assignments', 'mechanic-assign'],
    ['post', 'mechanic-assignments/{assignment}/release', 'mechanic-release'],
    ['post', 'orders/{order}/diagnoses', 'diagnosis-record'],
    ['post', 'diagnoses/{diagnosis}/revisions', 'diagnosis-revise'],
    ['post', 'orders/{order}/admission-links', 'admission-link'],
    ['post', 'admission-links/{link}/invalidate', 'admission-link-invalidate'],
] as [$method, $uri, $name]) {
    Route::$method($uri, WorkshopCoreController::class)->whereUuid(['order', 'report', 'link', 'assignment', 'diagnosis'])->name('workshop.core.'.$name);
}
