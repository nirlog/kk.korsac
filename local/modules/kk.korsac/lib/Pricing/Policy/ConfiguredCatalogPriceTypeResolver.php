<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing\Policy;

use KK\Korsac\Configurator\ConfiguratorException;

final class ConfiguredCatalogPriceTypeResolver implements CatalogPriceTypeResolverInterface
{
    public const MODULE_ID = 'kk.korsac';

    public function __construct(private readonly PricingSettingsStoreInterface $settings = new BitrixPricingSettingsStore()) {}

    public function resolve(int $iblockId, string $channel): int
    {
        PriceChannel::assert($channel);
        $value = $this->settings->get(PricingConfigurationKeys::priceType($iblockId, $channel));
        if (!ctype_digit($value) || (int)$value <= 0) {
            throw new ConfiguratorException(['code' => 'catalog_price_type_not_configured', 'iblockId' => $iblockId, 'channel' => $channel]);
        }
        return (int)$value;
    }
}
