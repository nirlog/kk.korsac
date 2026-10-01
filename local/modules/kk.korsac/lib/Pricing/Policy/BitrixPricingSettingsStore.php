<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing\Policy;

use Bitrix\Main\Config\Option;

final class BitrixPricingSettingsStore implements PricingSettingsStoreInterface
{
    public function get(string $key): string { return Option::get(ConfiguredCatalogPriceTypeResolver::MODULE_ID, $key, ''); }
    public function set(string $key, string $value): void { Option::set(ConfiguredCatalogPriceTypeResolver::MODULE_ID, $key, $value); }
}
