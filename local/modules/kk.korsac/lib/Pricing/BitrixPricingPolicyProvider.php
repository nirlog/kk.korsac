<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use Bitrix\Main\Config\Option;
use Closure;
use KK\Korsac\Configurator\ConfiguratorException;

final class BitrixPricingPolicyProvider implements PricingPolicyProviderInterface
{
    /** @param Closure(string):string|null $reader */
    public function __construct(private readonly ?Closure $reader = null) {}

    public function get(int $iblockId, int $priceTypeId): PricingPolicy
    {
        $markup = $this->read(PricingConfiguration::markupKey($iblockId, $priceTypeId));
        $fixed = $this->read(PricingConfiguration::fixedAdjustmentKey($iblockId, $priceTypeId));
        if ($markup === null || $fixed === null) {
            throw new ConfiguratorException(['code' => 'pricing_policy_not_configured']);
        }
        return new PricingPolicy(self::nonNegativeInt($markup), self::nonNegativeInt($fixed));
    }

    private function read(string $key): ?string
    {
        $value = $this->reader === null
            ? Option::get(PricingConfiguration::MODULE_ID, $key, '')
            : ($this->reader)($key);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nonNegativeInt(string $value): int
    {
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string)PHP_INT_MAX;
        if (preg_match('/^\d+$/D', $value) !== 1 || strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new ConfigurationPricingException(['code' => 'invalid_pricing_policy']);
        }
        return (int)$normalized;
    }
}
