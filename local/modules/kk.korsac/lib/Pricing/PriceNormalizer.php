<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

final class PriceNormalizer
{
    public static function toMinor(mixed $value): int
    {
        if (is_int($value)) {
            $major = (string)$value;
        } elseif (is_float($value) && is_finite($value)) {
            $major = number_format($value, 2, '.', '');
        } elseif (is_string($value)) {
            $major = $value;
        } else {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price']);
        }

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/D', $major, $parts) !== 1) {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price']);
        }

        $negative = $parts[1] === '-';
        $whole = ltrim($parts[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $parts[3] ?? '';
        $minorFraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $subCentTail = substr($fraction, 2);
        if ($subCentTail !== '' && strspn($subCentTail, '0') !== strlen($subCentTail)) {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price']);
        }

        $maximumWhole = intdiv(PHP_INT_MAX, 100);
        $limit = (string)$maximumWhole;
        if (strlen($whole) > strlen($limit) || (strlen($whole) === strlen($limit) && strcmp($whole, $limit) > 0)) {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price']);
        }
        if ((int)$whole === $maximumWhole && (int)$minorFraction > PHP_INT_MAX % 100) {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price']);
        }
        $minor = ((int)$whole * 100) + (int)$minorFraction;
        return $negative ? -$minor : $minor;
    }
}
