<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing\Policy;

final class PricingConfigurationKeys
{
    public static function priceType(int $iblockId, string $channel): string
    {
        return 'pricing_price_type_' . $iblockId . '_' . PriceChannel::assert($channel);
    }

    public static function policy(int $iblockId, int $priceTypeId): string
    {
        return "pricing_policy_{$iblockId}_{$priceTypeId}";
    }
}
