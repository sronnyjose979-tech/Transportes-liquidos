<?php

namespace App\Support;

use App\Modules\FleetAndDocuments\Domain\DocumentOperationFailed;
use PDOException;
use Throwable;

final class SafeOperationalFailure
{
    public static function database(Throwable $exception): ?PDOException
    {
        do {
            if ($exception instanceof PDOException) {
                return $exception;
            }
        } while ($exception = $exception->getPrevious());

        return null;
    }

    public static function documentary(Throwable $exception): bool
    {
        do {
            if ($exception instanceof DocumentOperationFailed) {
                return true;
            }
        } while ($exception = $exception->getPrevious());

        return false;
    }
}
