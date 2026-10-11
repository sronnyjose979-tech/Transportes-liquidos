<?php

namespace App\Modules\FleetAndDocuments\Console;

use App\Modules\FleetAndDocuments\Infrastructure\PrivateDocumentStorage;
use Illuminate\Console\Command;

class ReconcileDocumentStorage extends Command
{
    protected $signature = 'fleet-documents:reconcile-storage';

    protected $description = 'Compensar cargas pendientes en el almacenamiento documental privado, conservando archivos históricos';

    public function handle(PrivateDocumentStorage $storage): int
    {
        $count = $storage->reconcile();
        $this->info('Operaciones pendientes reconciliadas: '.$count);

        return self::SUCCESS;
    }
}
