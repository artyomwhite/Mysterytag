<?php

declare(strict_types=1);

namespace App;

/**
 * Amount is handled as a string to avoid float rounding problems.
 */
final class Amount
{
    /** Non-negative decimal: digits with optional fractional part (no sign, no exponent). */
    public static function isValid(string $value): bool
    {
        return preg_match('/^\d{1,12}(\.\d{1,6})?$/', $value) === 1;
    }

    /** "49.90" -> "49.9", "049.0" -> "49", "0.50" -> "0.5". Same number => same string. */
    public static function normalize(string $value): string
    {
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');

        $int = ltrim($int, '0');
        $int = $int === '' ? '0' : $int;
        $frac = rtrim($frac, '0');

        return $frac === '' ? $int : $int . '.' . $frac;
    }
}
