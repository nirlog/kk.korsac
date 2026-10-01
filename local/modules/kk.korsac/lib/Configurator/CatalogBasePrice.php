<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

final readonly class CatalogBasePrice
{
    public function __construct(
        public int $priceTypeId,
        public string $currency,
        public int $priceMinor,
    ) {}
}
