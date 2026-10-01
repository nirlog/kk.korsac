<?php

declare(strict_types=1);

namespace KK\Korsac\Cart;

final readonly class ConfigurationSnapshot
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $key,
        public string $hash,
        public string $siteId,
        public int $iblockId,
        public int $productId,
        public int $priceTypeId,
        public string $currency,
        public int $basePriceMinor,
        public int $configurationDeltaMinor,
        public int $finalPriceMinor,
        public string $payload,
        public array $display,
    ) {}
}
