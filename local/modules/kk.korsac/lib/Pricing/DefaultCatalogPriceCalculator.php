<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use KK\Korsac\Catalog\ProductConfiguration;
use KK\Korsac\Pricing\Policy\PricingPolicy;

final class DefaultCatalogPriceCalculator
{
    public function __construct(private readonly OptionPriceProviderInterface $retailPrices) {}

    public function calculate(ProductConfiguration $configuration, PricingPolicy $policy): DefaultCatalogPriceResult
    {
        $groups = [];
        $components = 0;
        foreach ($configuration->toArray() as $group => $definition) {
            if ($definition['mode'] === 'multiple') {
                $groups[$group] = ['mode' => 'multiple', 'included' => false, 'retailPriceMinor' => 0];
                continue;
            }
            $xmlId = $definition['default'];
            $price = $xmlId === null ? 0 : $this->retailPrices->getPriceMinor($group, $xmlId);
            $components = self::checkedAdd($components, $price);
            $groups[$group] = ['mode' => 'single', 'xmlId' => $xmlId, 'retailPriceMinor' => $price];
        }
        $total = self::checkedAdd($components, $policy->systemFixedAdjustmentMinor);
        return new DefaultCatalogPriceResult($groups, $components, $policy->systemFixedAdjustmentMinor, $total);
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) { throw new ConfigurationPricingException(['code' => 'price_overflow']); }
        return $left + $right;
    }
}
