<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

final class PricingConfiguration
{
    public const MODULE_ID = 'kk.korsac';

    public static function priceTypeKey(int $iblockId, string $channel): string
    {
        return 'pricing.price_type.' . $iblockId . '.' . PriceChannel::normalize($channel);
    }

    public static function markupKey(int $iblockId, int $priceTypeId): string
    {
        return 'pricing.policy.' . $iblockId . '.' . $priceTypeId . '.markup_bps';
    }

    public static function fixedAdjustmentKey(int $iblockId, int $priceTypeId): string
    {
        return 'pricing.policy.' . $iblockId . '.' . $priceTypeId . '.fixed_adjustment_minor';
    }
}
