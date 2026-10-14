<?php

namespace App\Modules\BillingAndPayments\Application;

use InvalidArgumentException;

/** Decimal strings and integer minor units only; PHP 64-bit required. */
final class Money
{
    public static function minor(string $amount): int
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D', $amount, $parts)) {
            throw new InvalidArgumentException('Importe decimal inválido.');
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function format(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** HALF_UP available for explicit decimal rounding; accepted writes remain scale <= 2. */
    public static function roundHalfUp(string $amount): string
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]+))?$/D', $amount, $parts)) {
            throw new InvalidArgumentException('Importe decimal inválido.');
        }
        $fraction = str_pad($parts[2] ?? '', 3, '0');
        $minor = ((int) $parts[1]) * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return self::format($minor);
    }
}
