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
        } elseif (is_string($value) && preg_match('/^[+-]?\d+(?:\.\d{1,2})?$/D', $value) === 1) {
            $major = $value;
        } else {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price']);
        }

        $negative = str_starts_with($major, '-');
        $unsigned = ltrim($major, '+-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        $maximumWhole = intdiv(PHP_INT_MAX, 100);
        $limit = (string)$maximumWhole;
        if (strlen($whole) > strlen($limit) || (strlen($whole) === strlen($limit) && strcmp($whole, $limit) > 0)) {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price']);
        }
        if ((int)$whole === $maximumWhole && (int)$fraction > PHP_INT_MAX % 100) {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price']);
        }
        $minor = ((int)$whole * 100) + (int)$fraction;
        return $negative ? -$minor : $minor;
    }
}
