<?php

declare(strict_types=1);

namespace KK\Korsac\Cart;

use KK\Korsac\Configurator\OptionViewProviderInterface;
use KK\Korsac\Configurator\ConfiguredProductQuote;

final class ConfigurationSnapshotBuilder
{
    public function __construct(private readonly OptionViewProviderInterface $views) {}

    public function build(ConfiguredProductQuote $quote, string $siteId): ConfigurationSnapshot
    {
        $selection = $quote->selection->toArray();
        $configuration = $quote->configuration->toArray();
        $display = [];
        foreach ($selection as $group => $value) {
            $ids = is_array($value) ? $value : ($value === null ? [] : [$value]);
            if ($ids === []) {
                continue;
            }
            $items = [];
            foreach ($ids as $xmlId) {
                $view = $this->views->get($group, $xmlId);
                $items[] = ['xmlId' => $view->xmlId, 'name' => $view->name];
            }
            $display[$group] = ['mode' => $configuration[$group]['mode'], 'items' => $items];
        }
        $payload = [
            'schemaVersion' => ConfigurationSnapshot::SCHEMA_VERSION,
            'product' => ['iblockId' => $quote->iblockId, 'productId' => $quote->productId],
            'price' => [
                'priceTypeId' => $quote->priceTypeId,
                'currency' => $quote->currency,
                'basePriceMinor' => $quote->basePriceMinor,
                'configurationDeltaMinor' => $quote->configurationDeltaMinor,
                'finalPriceMinor' => $quote->finalPriceMinor,
                'groupDeltas' => $quote->groupDeltas,
            ],
            'selection' => $selection,
            'display' => $display,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new ConfigurationSnapshot(
            bin2hex(random_bytes(32)), hash('sha256', $json), $siteId, $quote->iblockId, $quote->productId,
            $quote->priceTypeId, $quote->currency, $quote->basePriceMinor, $quote->configurationDeltaMinor,
            $quote->finalPriceMinor, $json, $display,
        );
    }
}
