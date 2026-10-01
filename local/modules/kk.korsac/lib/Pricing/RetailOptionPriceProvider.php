<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

final class RetailOptionPriceProvider implements OptionPriceProviderInterface
{
    private array $cache = [];

    public function __construct(
        private readonly OptionPriceProviderInterface $rawPrices,
        private readonly PricingPolicy $policy,
        private readonly RetailPriceCalculator $calculator = new RetailPriceCalculator(),
    ) {}

    public function getPriceMinor(string $group, string $xmlId): int
    {
        $key = $group . "\0" . $xmlId;
        return $this->cache[$key] ??= $this->calculator->calculate(
            $this->rawPrices->getPriceMinor($group, $xmlId),
            OptionPricingModeRegistry::mode($group),
            $this->policy,
        );
    }
}
