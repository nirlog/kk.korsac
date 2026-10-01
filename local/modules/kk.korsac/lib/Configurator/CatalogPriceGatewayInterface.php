<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

interface CatalogPriceGatewayInterface
{
    /** @return array{priceTypeId:int,price:mixed,currency:string}|null */
    public function findBasePrice(int $productId): ?array;
}
