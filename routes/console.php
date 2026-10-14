<?php

use App\Modules\Expenses\Infrastructure\PrivateReceiptStorage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('expenses:reconcile-receipts', function () {
    $resolved = app(PrivateReceiptStorage::class)->reconcile();
    $this->info('Comprobantes pendientes reconciliados: '.$resolved);
})->purpose('Reconciliar cargas privadas pendientes sin eliminar comprobantes históricos');
