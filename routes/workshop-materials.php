<?php

use App\Modules\Workshop\Http\Controllers\WorkshopAttachmentController;
use App\Modules\Workshop\Http\Controllers\WorkshopMaterialController;
use App\Modules\Workshop\Http\Requests\WorkshopMaterialRequest;
use Illuminate\Support\Facades\Route;

foreach (['delivery' => 'deliveries', 'usage' => 'usages', 'need' => 'material-needs', 'external' => 'external-repairs', 'cost' => 'costs'] as $action => $path) {
    Route::post('orders/{order}/'.$path, [WorkshopMaterialController::class, $action])->whereUuid('order')->name('workshop.material.'.$action);
}
Route::get('orders/{order}/cost-totals', [WorkshopMaterialController::class, 'totals'])->whereUuid('order')->name('workshop.material.totals');
Route::post('facts/{kind}/{fact}/rectifications', [WorkshopMaterialController::class, 'rectify'])->whereIn('kind', array_keys(WorkshopMaterialRequest::TABLES))->whereUuid('fact')->name('workshop.material.rectify');
Route::post('decisions/{decision}/facts/{kind}/apply', [WorkshopMaterialController::class, 'revise'])->whereIn('kind', ['delivery', 'usage', 'external', 'cost', 'need'])->whereUuid('decision')->name('workshop.material.revise');
Route::post('rectifications/apply-batch', [WorkshopMaterialController::class, 'batch'])->name('workshop.material.batch');
Route::get('orders/{order}/attachments', [WorkshopAttachmentController::class, 'index'])->whereUuid('order')->name('workshop.attachments.index');
Route::post('orders/{order}/attachment-reservations', [WorkshopAttachmentController::class, 'reserve'])->whereUuid('order')->name('workshop.attachments.reserve');
foreach (['confirm', 'release'] as $action) {
    Route::post('attachment-reservations/{reservation}/'.$action, [WorkshopAttachmentController::class, $action])->whereUuid('reservation')->name('workshop.attachments.'.$action);
}
Route::get('attachment-reservations/{reservation}', [WorkshopAttachmentController::class, 'receipt'])->whereUuid('reservation')->name('workshop.attachments.receipt');
Route::get('attachments/{attachment}/download', [WorkshopAttachmentController::class, 'download'])->whereUuid('attachment')->name('workshop.attachments.download');
