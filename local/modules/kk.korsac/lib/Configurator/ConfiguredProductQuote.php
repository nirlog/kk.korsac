<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

use KK\Korsac\Catalog\ProductConfiguration;
use KK\Korsac\Pricing\ConfigurationSelection;

final readonly class ConfiguredProductQuote
{
    public function __construct(
        public int $iblockId,
        public int $productId,
        public ProductConfiguration $configuration,
        public ConfigurationSelection $selection,
        public int $priceTypeId,
        public string $currency,
        public int $basePriceMinor,
        public int $configurationDeltaMinor,
        public int $finalPriceMinor,
        public array $groupDeltas,
    ) {}

    public function publicPrice(): array
    {
        return [
            'priceTypeId' => $this->priceTypeId,
            'currency' => $this->currency,
            'basePriceMinor' => $this->basePriceMinor,
            'configurationDeltaMinor' => $this->configurationDeltaMinor,
            'finalPriceMinor' => $this->finalPriceMinor,
            'groupDeltas' => $this->groupDeltas,
        ];
    }
}
