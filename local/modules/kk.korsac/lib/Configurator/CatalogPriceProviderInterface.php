<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

interface CatalogPriceProviderInterface
{
    public function get(int $productId, int $priceTypeId): CatalogPrice;
}
