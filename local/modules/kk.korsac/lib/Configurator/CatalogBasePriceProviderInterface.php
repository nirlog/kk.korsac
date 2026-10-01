<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

interface CatalogBasePriceProviderInterface
{
    public function get(int $productId): CatalogBasePrice;
}
