<?php

namespace App\Modules\TransportAndDispatch\Models\Concerns;

use LogicException;

trait PreservesTransportHistory
{
    public static function bootPreservesTransportHistory(): void
    {
        static::updating(fn () => throw new LogicException('El historial de transporte es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial de transporte es inmutable.'));
    }
}
