<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use KK\Korsac\Catalog\ProductConfiguration;

final class DefaultConfigurationCostCalculator
{
    public function __construct(private readonly OptionPriceProviderInterface $prices) {}

    public function calculate(ProductConfiguration $configuration): DefaultConfigurationCostResult
    {
        $groups = [];
        $total = 0;

        foreach ($configuration->toArray() as $group => $definition) {
            if ($definition['mode'] === 'multiple') {
                $groups[$group] = ['mode' => 'multiple', 'included' => false, 'priceMinor' => 0];
                continue;
            }

            $xmlId = $definition['default'];
            $price = $xmlId === null ? 0 : $this->prices->getPriceMinor($group, $xmlId);
            if ($price > 0 && $total > PHP_INT_MAX - $price) {
                throw new ConfigurationPricingException(['code' => 'price_overflow']);
            }
            $total += $price;
            $groups[$group] = ['mode' => 'single', 'xmlId' => $xmlId, 'priceMinor' => $price];
        }

        return new DefaultConfigurationCostResult($groups, $total);
    }
}
