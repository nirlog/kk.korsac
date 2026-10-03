<?php

declare(strict_types=1);

namespace KK\Korsac\Admin;

use InvalidArgumentException;

final class DecimalParser
{
    public static function toBasisPoints(string $value): int
    {
        return self::toScaledInteger($value, 2, 'invalid_markup');
    }

    public static function toMinorUnits(string $value): int
    {
        return self::toScaledInteger($value, 2, 'invalid_fixed_adjustment');
    }

    public static function formatScaled(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('A non-negative value is required.');
        }

        return intdiv($value, 100) . '.' . str_pad((string)($value % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function toScaledInteger(string $value, int $scale, string $errorCode): int
    {
        $normalized = str_replace(',', '.', trim($value));
        if (preg_match('/^(\d+)(?:\.(\d{1,' . $scale . '}))?$/D', $normalized, $matches) !== 1) {
            throw new InvalidArgumentException($errorCode);
        }
        $fraction = str_pad($matches[2] ?? '', $scale, '0');
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $digits = ltrim($whole . $fraction, '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException($errorCode);
        }
        return (int)$digits;
    }
}
