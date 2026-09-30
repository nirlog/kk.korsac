<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

interface OptionPriceProviderInterface
{
    public function getPriceMinor(string $group, string $xmlId): int;
}
