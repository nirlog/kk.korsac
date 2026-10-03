<?php

declare(strict_types=1);

namespace KK\Korsac\Admin;

use InvalidArgumentException;
use KK\Korsac\Pricing\BitrixPricingPolicyProvider;
use KK\Korsac\Pricing\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Pricing\PriceChannel;
use KK\Korsac\Pricing\PricingConfiguration;
use KK\Korsac\Pricing\PricingPolicy;

final class PricingConfigurationAdminService
{
    public const CHANNELS = [PriceChannel::RETAIL, PriceChannel::BUSINESS];

    public function __construct(private readonly PricingAdminGatewayInterface $gateway) {}

    public function catalogs(): array { return $this->gateway->catalogs(); }
    public function priceTypes(): array { return $this->gateway->priceTypes(); }

    public function save(int $iblockId, array $submittedChannels): void
    {
        if (!$this->gateway->catalogExists($iblockId)) {
            throw new InvalidArgumentException('catalog_not_found');
        }
        $validated = [];
        foreach (self::CHANNELS as $channel) {
            PriceChannel::normalize($channel);
            $input = $submittedChannels[$channel] ?? [];
            $enabled = ($input['enabled'] ?? '') === 'Y';
            if (!$enabled) {
                $validated[$channel] = null;
                continue;
            }
            $priceTypeId = filter_var($input['priceTypeId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($priceTypeId === false || !$this->gateway->priceTypeExists((int)$priceTypeId)) {
                throw new InvalidArgumentException($channel . ': invalid_price_type');
            }
            $markup = DecimalParser::toBasisPoints((string)($input['markupPercent'] ?? ''));
            $fixed = DecimalParser::toMinorUnits((string)($input['fixedRub'] ?? ''));
            new PricingPolicy($markup, $fixed);
            $candidate = ['priceTypeId' => (int)$priceTypeId, 'markupBasisPoints' => $markup, 'fixedAdjustmentMinor' => $fixed];
            foreach ($validated as $other) {
                if ($other !== null && $other['priceTypeId'] === $candidate['priceTypeId'] && $other !== $candidate) {
                    throw new InvalidArgumentException('shared_price_type_policy_conflict');
                }
            }
            $validated[$channel] = $candidate;
        }

        // All input is validated before the first Option write.
        foreach ($validated as $channel => $policy) {
            $mappingKey = PricingConfiguration::priceTypeKey($iblockId, $channel);
            if ($policy === null) {
                $this->gateway->removeOption($mappingKey);
                continue;
            }
            $priceTypeId = $policy['priceTypeId'];
            $this->gateway->writeOption($mappingKey, (string)$priceTypeId);
            $this->gateway->writeOption(PricingConfiguration::markupKey($iblockId, $priceTypeId), (string)$policy['markupBasisPoints']);
            $this->gateway->writeOption(PricingConfiguration::fixedAdjustmentKey($iblockId, $priceTypeId), (string)$policy['fixedAdjustmentMinor']);
        }
        // Unreferenced policy keys are intentionally retained; mapping removal is authoritative and shared policies stay safe.
    }

    public function view(int $iblockId): array
    {
        if (!$this->gateway->catalogExists($iblockId)) {
            throw new InvalidArgumentException('catalog_not_found');
        }
        $reader = fn(string $key): ?string => $this->gateway->readOption($key);
        $resolver = new ConfiguredCatalogPriceTypeResolver($reader);
        $provider = new BitrixPricingPolicyProvider($reader);
        $view = [];
        foreach (self::CHANNELS as $channel) {
            $mapping = $this->gateway->readOption(PricingConfiguration::priceTypeKey($iblockId, $channel));
            try {
                $priceTypeId = $resolver->resolve($iblockId, $channel);
            } catch (\Throwable) {
                $view[$channel] = [
                    'status' => $mapping === null ? 'unconfigured' : 'error',
                    'enabled' => $mapping !== null,
                    'priceTypeId' => null,
                    'markupBasisPoints' => null,
                    'fixedAdjustmentMinor' => null,
                ];
                continue;
            }
            try {
                $policy = $provider->get($iblockId, $priceTypeId);
                $view[$channel] = [
                    'status' => $this->gateway->priceTypeExists($priceTypeId) ? 'configured' : 'error',
                    'enabled' => true,
                    'priceTypeId' => $priceTypeId,
                    'markupBasisPoints' => $policy->markupBasisPoints,
                    'fixedAdjustmentMinor' => $policy->systemFixedAdjustmentMinor,
                ];
            } catch (\Throwable) {
                $view[$channel] = ['status' => 'error', 'enabled' => true, 'priceTypeId' => $priceTypeId, 'markupBasisPoints' => null, 'fixedAdjustmentMinor' => null];
            }
        }
        return $view;
    }
}
