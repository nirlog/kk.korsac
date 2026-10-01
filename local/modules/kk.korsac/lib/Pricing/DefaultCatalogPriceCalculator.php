<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use KK\Korsac\Catalog\ProductConfiguration;

final class DefaultCatalogPriceCalculator
{
    public function __construct(
        private readonly OptionPriceProviderInterface $retailPrices,
        private readonly PricingPolicy $policy,
    ) {}

    public function calculate(ProductConfiguration $configuration): DefaultCatalogPriceResult
    {
        $groups = [];
        $components = 0;
        foreach ($configuration->toArray() as $group => $definition) {
            if ($definition['mode'] === 'multiple') {
                $groups[$group] = ['mode' => 'multiple', 'included' => false, 'retailMinor' => 0];
                continue;
            }
            $xmlId = $definition['default'];
            $price = $xmlId === null ? 0 : $this->retailPrices->getPriceMinor($group, $xmlId);
            $components = self::checkedAdd($components, $price);
            $groups[$group] = ['mode' => 'single', 'xmlId' => $xmlId, 'retailMinor' => $price];
        }
        $total = self::checkedAdd($components, $this->policy->systemFixedAdjustmentMinor);
        return new DefaultCatalogPriceResult($groups, $components, $this->policy->systemFixedAdjustmentMinor, $total);
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new ConfigurationPricingException(['code' => 'price_overflow']);
        }
        return $left + $right;
    }
}
