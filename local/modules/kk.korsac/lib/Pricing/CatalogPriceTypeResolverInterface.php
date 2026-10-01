<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

interface CatalogPriceTypeResolverInterface
{
    public function resolve(int $iblockId, string $channel): int;
}
