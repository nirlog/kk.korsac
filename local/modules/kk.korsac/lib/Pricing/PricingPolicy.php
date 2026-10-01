<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

final readonly class PricingPolicy
{
    public function __construct(
        public int $markupBasisPoints,
        public int $systemFixedAdjustmentMinor,
    ) {
        if ($markupBasisPoints < 0 || $systemFixedAdjustmentMinor < 0) {
            throw new ConfigurationPricingException(['code' => 'invalid_pricing_policy']);
        }
    }
}
