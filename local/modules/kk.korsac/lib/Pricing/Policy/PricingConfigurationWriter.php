<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing\Policy;

final class PricingConfigurationWriter
{
    public function __construct(private readonly PricingSettingsStoreInterface $settings = new BitrixPricingSettingsStore()) {}

    public function configure(int $iblockId, string $channel, int $priceTypeId, PricingPolicy $policy): void
    {
        if ($iblockId <= 0 || $priceTypeId <= 0) { throw new \InvalidArgumentException('Iblock and price type IDs must be positive'); }
        PriceChannel::assert($channel);
        $this->settings->set(PricingConfigurationKeys::policy($iblockId, $priceTypeId), json_encode([
            'markupBasisPoints' => $policy->markupBasisPoints,
            'systemFixedAdjustmentMinor' => $policy->systemFixedAdjustmentMinor,
        ], JSON_THROW_ON_ERROR));
        $this->settings->set(PricingConfigurationKeys::priceType($iblockId, $channel), (string)$priceTypeId);
    }
}
