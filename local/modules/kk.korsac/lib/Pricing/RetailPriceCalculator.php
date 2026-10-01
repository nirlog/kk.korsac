<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

final class RetailPriceCalculator
{
    private const BASIS_POINT_DENOMINATOR = 10000;

    public function calculate(int $rawMinor, string $mode, PricingPolicy $policy): int
    {
        if ($rawMinor < 0) {
            throw new ConfigurationPricingException(['code' => 'negative_option_price']);
        }
        if ($mode === OptionPricingModeRegistry::RETAIL) {
            return $rawMinor;
        }
        if ($mode !== OptionPricingModeRegistry::PROCUREMENT) {
            throw new ConfigurationPricingException(['code' => 'unknown_pricing_mode']);
        }

        $whole = intdiv($rawMinor, self::BASIS_POINT_DENOMINATOR);
        $remainder = $rawMinor % self::BASIS_POINT_DENOMINATOR;
        $basisWhole = intdiv($policy->markupBasisPoints, self::BASIS_POINT_DENOMINATOR);
        $basisRemainder = $policy->markupBasisPoints % self::BASIS_POINT_DENOMINATOR;

        $markup = self::checkedMultiply($whole, $policy->markupBasisPoints);
        $markup = self::checkedAdd($markup, self::checkedMultiply($remainder, $basisWhole));
        $smallProduct = $remainder * $basisRemainder;
        $rounded = intdiv($smallProduct, self::BASIS_POINT_DENOMINATOR);
        if (($smallProduct % self::BASIS_POINT_DENOMINATOR) >= 5000) {
            ++$rounded;
        }
        $markup = self::checkedAdd($markup, $rounded);
        return self::checkedAdd($rawMinor, $markup);
    }

    private static function checkedMultiply(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new ConfigurationPricingException(['code' => 'price_overflow']);
        }
        return $left * $right;
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new ConfigurationPricingException(['code' => 'price_overflow']);
        }
        return $left + $right;
    }
}
