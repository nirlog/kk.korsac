<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use KK\Korsac\Pricing\Policy\PricingPolicy;

final class RetailPriceCalculator
{
    public function calculate(string $group, int $rawMinor, PricingPolicy $policy): int
    {
        if ($rawMinor < 0) {
            throw new ConfigurationPricingException(['code' => 'negative_option_price', 'group' => $group]);
        }
        if (OptionPricingModeRegistry::mode($group) === OptionPricingModeRegistry::RETAIL || $rawMinor === 0 || $policy->markupBasisPoints === 0) {
            return $rawMinor;
        }

        $wholeRate = intdiv($policy->markupBasisPoints, 10000);
        $partialRate = $policy->markupBasisPoints % 10000;
        $wholePrice = intdiv($rawMinor, 10000);
        $priceRemainder = $rawMinor % 10000;
        $markup = self::checkedMultiply($rawMinor, $wholeRate);
        $markup = self::checkedAdd($markup, self::checkedMultiply($wholePrice, $partialRate));
        $partialNumerator = $priceRemainder * $partialRate;
        $partialMarkup = intdiv($partialNumerator, 10000);
        if ($partialNumerator % 10000 >= 5000) {
            $partialMarkup = self::checkedAdd($partialMarkup, 1);
        }
        return self::checkedAdd($rawMinor, self::checkedAdd($markup, $partialMarkup));
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
