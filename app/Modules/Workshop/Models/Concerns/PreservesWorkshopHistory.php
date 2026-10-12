<?php

namespace App\Modules\Workshop\Models\Concerns;

use LogicException;

trait PreservesWorkshopHistory
{
    public static function bootPreservesWorkshopHistory(): void
    {
        static::updating(fn () => throw new LogicException('El historial de taller es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial de taller es inmutable.'));
    }
}
