<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use KK\Korsac\Catalog\ProductConfiguration;

final class ConfigurationPriceCalculator
{
    public function __construct(private readonly OptionPriceProviderInterface $prices) {}

    public function calculate(ProductConfiguration $configuration, ConfigurationSelection $selection, int $basePriceMinor): ConfigurationPriceResult
    {
        if ($basePriceMinor < 0) {
            throw new ConfigurationPricingException(['code' => 'invalid_base_price']);
        }
        $selectedGroups = $selection->toArray();
        $breakdown = [];
        $totalDelta = 0;
        foreach ($configuration->toArray() as $group => $definition) {
            if ($definition['mode'] === 'single') {
                $default = $definition['default'];
                $selected = $selectedGroups[$group];
                $defaultPrice = $default === null ? 0 : $this->prices->getPriceMinor($group, $default);
                $selectedPrice = $selected === null ? 0 : ($selected === $default ? $defaultPrice : $this->prices->getPriceMinor($group, $selected));
                $delta = $selectedPrice - $defaultPrice;
                $breakdown[$group] = ['mode' => 'single', 'default' => $default, 'selected' => $selected, 'defaultPriceMinor' => $defaultPrice, 'selectedPriceMinor' => $selectedPrice, 'deltaMinor' => $delta];
            } else {
                $items = [];
                $delta = 0;
                foreach ($selectedGroups[$group] as $xmlId) {
                    $price = $this->prices->getPriceMinor($group, $xmlId);
                    $delta = self::checkedAdd($delta, $price);
                    $items[] = ['xmlId' => $xmlId, 'priceMinor' => $price];
                }
                $breakdown[$group] = ['mode' => 'multiple', 'selected' => $items, 'deltaMinor' => $delta];
            }
            $totalDelta = self::checkedAdd($totalDelta, $delta);
        }
        $final = self::checkedAdd($basePriceMinor, $totalDelta);
        if ($final < 0) {
            throw new ConfigurationPricingException(['code' => 'negative_final_price']);
        }
        return new ConfigurationPriceResult($basePriceMinor, $breakdown, $totalDelta, $final);
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new ConfigurationPricingException(['code' => 'price_overflow']);
        }
        return $left + $right;
    }
}
