<?php

use App\Modules\Workshop\Http\Controllers\WorkshopProcessController;
use Illuminate\Support\Facades\Route;

$process = WorkshopProcessController::class;
foreach (['scope' => 'scopes', 'order-request' => 'authorization-requests', 'activity' => 'activities', 'time' => 'time-events', 'finish' => 'finish', 'close' => 'close'] as $action => $path) {
    Route::post('orders/{order}/'.$path, [$process, 'order'])->whereUuid('order')->defaults('action', $action);
}
Route::get('orders/{order}/times', [$process, 'times'])->whereUuid('order');
Route::post('authorization-requests/{authorization}/decisions', [$process, 'decide'])->whereUuid('authorization')->defaults('action', 'decide');
foreach (['scope-apply' => 'apply-scope', 'order-apply' => 'apply-order', 'exit' => 'exit'] as $action => $path) {
    Route::post('decisions/{decision}/'.$path, [$process, 'apply'])->whereUuid('decision')->defaults('action', $action);
}
foreach (['admit' => 'admissions', 'historical' => 'historical-observations', 'impediment' => 'impediments'] as $action => $path) {
    Route::post('assets/{asset}/'.$path, [$process, 'asset'])->whereUuid('asset')->defaults('action', $action);
}
Route::post('impediments/{impediment}/resolve', [$process, 'resolve'])->whereUuid('impediment')->defaults('action', 'resolve');
Route::post('admissions/{admission}/exit-requests', [$process, 'requestExit'])->whereUuid('admission')->defaults('action', 'exit-request');
