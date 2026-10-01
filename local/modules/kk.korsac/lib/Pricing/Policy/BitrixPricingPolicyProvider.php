<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing\Policy;

use JsonException;
use KK\Korsac\Configurator\ConfiguratorException;

final class BitrixPricingPolicyProvider implements PricingPolicyProviderInterface
{
    public function __construct(private readonly PricingSettingsStoreInterface $settings = new BitrixPricingSettingsStore()) {}

    public function get(int $iblockId, int $priceTypeId): PricingPolicy
    {
        $raw = $this->settings->get(PricingConfigurationKeys::policy($iblockId, $priceTypeId));
        try { $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (JsonException) { $value = null; }
        if (!is_array($value) || !is_int($value['markupBasisPoints'] ?? null) || !is_int($value['systemFixedAdjustmentMinor'] ?? null)) {
            throw new ConfiguratorException(['code' => 'pricing_policy_not_configured', 'iblockId' => $iblockId, 'priceTypeId' => $priceTypeId]);
        }
        return new PricingPolicy($value['markupBasisPoints'], $value['systemFixedAdjustmentMinor']);
    }
}
