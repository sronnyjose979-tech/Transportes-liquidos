<?php

namespace App\Modules\FleetAndDocuments\Console;

use App\Modules\FleetAndDocuments\Domain\DocumentOperationFailed;
use App\Modules\FleetAndDocuments\Infrastructure\PrivateDocumentStorage;
use Illuminate\Console\Command;
use Throwable;

class ReconcileDocumentStorage extends Command
{
    protected $signature = 'fleet-documents:reconcile-storage';

    protected $description = 'Compensar cargas pendientes en el almacenamiento documental privado, conservando archivos históricos';

    public function handle(PrivateDocumentStorage $storage): int
    {
        try {
            $count = $storage->reconcile();
        } catch (Throwable $exception) {
            // Scheduled command stdout may itself be collected as a log. Do not
            // let Symfony print the private exception chain to the terminal.
            report($exception instanceof DocumentOperationFailed ? $exception
                : new DocumentOperationFailed('Fallo de reconciliación documental.', previous: $exception));
            $this->error('No se pudo reconciliar el almacenamiento documental. Revise el registro operativo seguro.');

            return self::FAILURE;
        }
        $this->info('Operaciones pendientes reconciliadas: '.$count);

        return self::SUCCESS;
    }
}
