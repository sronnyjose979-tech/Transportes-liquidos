<?php

use App\Modules\Expenses\Infrastructure\PrivateReceiptStorage;
use App\Modules\FleetAndDocuments\Application\DocumentAlerts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('expenses:reconcile-receipts', function () {
    $resolved = app(PrivateReceiptStorage::class)->reconcile();
    $this->info('Comprobantes pendientes reconciliados: '.$resolved);
})->purpose('Reconciliar cargas privadas pendientes sin eliminar comprobantes históricos');

Artisan::command('documents:check-expirations', function () {
    $summary = app(DocumentAlerts::class)->summary();
    $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
})->purpose('Consultar vencimientos actuales sin duplicar alertas ni enviar notificaciones');

Schedule::command('documents:check-expirations')
    ->dailyAt('06:00')->timezone('America/Costa_Rica')->withoutOverlapping();

// Opt-in maintenance window; never put the application down or stop workers implicitly.
if (preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', (string) config('backups.schedule_time'))) {
    Schedule::command('backups:run create'.(config('backups.writers_stopped') ? ' --quiesced' : ''))
        ->dailyAt(config('backups.schedule_time'))->timezone('America/Costa_Rica')->withoutOverlapping()->evenInMaintenanceMode();
}
