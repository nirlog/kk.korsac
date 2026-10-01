<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

final readonly class CatalogPrice
{
    public function __construct(
        public int $priceTypeId,
        public string $currency,
        public int $priceMinor,
    ) {}
}
