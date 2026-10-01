<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

interface PricingPolicyProviderInterface
{
    public function get(int $iblockId, int $priceTypeId): PricingPolicy;
}
