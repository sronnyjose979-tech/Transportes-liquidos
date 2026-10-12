<?php

namespace App\Modules\TransportAndDispatch\Models\Concerns;

use InvalidArgumentException;

trait ValidatesTransportVolume
{
    public static function bootValidatesTransportVolume(): void
    {
        static::saving(function (self $record): void {
            if (! preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', (string) $record->getAttributes()[$record->volumeAttribute])) {
                throw new InvalidArgumentException('Los litros deben tener hasta dos decimales.');
            }
        });
    }
}
