<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing\Policy;

interface PricingSettingsStoreInterface
{
    public function get(string $key): string;
    public function set(string $key, string $value): void;
}
