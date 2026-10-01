<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use Bitrix\Main\Config\Option;
use Closure;

final class ConfiguredCatalogPriceTypeResolver implements CatalogPriceTypeResolverInterface
{
    /** @param Closure(string):string|null $reader */
    public function __construct(private readonly ?Closure $reader = null) {}

    public function resolve(int $iblockId, string $channel): int
    {
        $key = PricingConfiguration::priceTypeKey($iblockId, $channel);
        $value = $this->reader === null
            ? Option::get(PricingConfiguration::MODULE_ID, $key, '')
            : ($this->reader)($key);
        if (!is_string($value) || preg_match('/^[1-9]\d*$/D', $value) !== 1 || (int)$value <= 0) {
            throw new ConfigurationPricingException(['code' => 'catalog_price_type_not_configured']);
        }
        return (int)$value;
    }
}
