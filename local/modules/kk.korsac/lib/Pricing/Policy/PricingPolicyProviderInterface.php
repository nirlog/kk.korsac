<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing\Policy;

interface PricingPolicyProviderInterface
{
    public function get(int $iblockId, int $priceTypeId): PricingPolicy;
}
