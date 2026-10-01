<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

use InvalidArgumentException;
use KK\Korsac\Pricing\ConfigurationPriceCalculator;
use KK\Korsac\Pricing\ConfigurationSelection;
use KK\Korsac\Pricing\OptionPriceProviderInterface;
use KK\Korsac\Pricing\CatalogPriceTypeResolverInterface;
use KK\Korsac\Pricing\PricingPolicyProviderInterface;
use KK\Korsac\Pricing\RetailOptionPriceProvider;
use KK\Korsac\Pricing\PriceChannel;
use KK\Korsac\Catalog\ProductConfigurationRepository;

final class ConfiguredProductPricingService implements ConfiguredProductPricingServiceInterface
{
    public function __construct(
        private readonly ProductConfigurationRepository $configurations,
        private readonly CatalogPriceProviderInterface $catalogPrices,
        private readonly OptionPriceProviderInterface $rawOptionPrices,
        private readonly CatalogPriceTypeResolverInterface $priceTypes,
        private readonly PricingPolicyProviderInterface $policies,
    ) {}

    public function quote(int $iblockId, int $productId, array $selection): ConfiguredProductQuote
    {
        if ($iblockId <= 0 || $productId <= 0) {
            throw new ConfiguratorException(['code' => 'invalid_request']);
        }
        try {
            $configuration = $this->configurations->get($iblockId, $productId);
        } catch (InvalidArgumentException) {
            throw new ConfiguratorException(['code' => 'product_not_found', 'iblockId' => $iblockId, 'productId' => $productId]);
        }
        $priceTypeId = $this->priceTypes->resolve($iblockId, PriceChannel::RETAIL);
        $policy = $this->policies->get($iblockId, $priceTypeId);
        $base = $this->catalogPrices->get($productId, $priceTypeId);
        $normalized = ConfigurationSelection::fromArray($configuration, $selection);
        $calculation = (new ConfigurationPriceCalculator(new RetailOptionPriceProvider($this->rawOptionPrices, $policy)))
            ->calculate($configuration, $normalized, $base->priceMinor)->toArray();
        return new ConfiguredProductQuote(
            $iblockId, $productId, $configuration, $normalized, $base->priceTypeId, $base->currency,
            $calculation['basePriceMinor'], $calculation['configurationDeltaMinor'], $calculation['finalPriceMinor'],
            array_map(static fn(array $group): int => $group['deltaMinor'], $calculation['groups']),
        );
    }
}
