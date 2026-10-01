<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

use InvalidArgumentException;
use KK\Korsac\Catalog\ProductConfiguration;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Pricing\ConfigurationPriceCalculator;
use KK\Korsac\Pricing\ConfigurationSelection;

final class ConfiguratorService
{
    public function __construct(
        private readonly ProductConfigurationRepository $configurations,
        private readonly CatalogBasePriceProviderInterface $basePrices,
        private readonly OptionViewProviderInterface $optionViews,
        private readonly ConfigurationPriceCalculator $calculator,
    ) {}

    public function get(int $iblockId, int $productId): array
    {
        [$configuration, $basePrice] = $this->currentState($iblockId, $productId);
        $selection = ConfigurationSelection::fromArray($configuration, []);
        $calculation = $this->calculator->calculate($configuration, $selection, $basePrice->priceMinor)->toArray();
        $groups = [];
        foreach ($configuration->toArray() as $group => $definition) {
            $choices = $definition['mode'] === 'single'
                ? array_values(array_filter(array_merge([$definition['default']], $definition['options']), static fn(mixed $id): bool => is_string($id)))
                : $definition['options'];
            $projectedChoices = [];
            foreach ($choices as $xmlId) {
                $candidate = ConfigurationSelection::fromArray($configuration, [$group => $definition['mode'] === 'single' ? $xmlId : [$xmlId]]);
                $candidateResult = $this->calculator->calculate($configuration, $candidate, $basePrice->priceMinor)->toArray();
                $view = $this->optionViews->get($group, $xmlId);
                $projectedChoices[] = [
                    'xmlId' => $view->xmlId,
                    'name' => $view->name,
                    'description' => $view->description,
                    'deltaMinor' => $candidateResult['groups'][$group]['deltaMinor'],
                ];
            }
            $groups[$group] = [
                'mode' => $definition['mode'],
                'default' => $definition['mode'] === 'single' ? $definition['default'] : [],
                ...($definition['mode'] === 'single' ? ['allowNull' => $definition['default'] === null] : []),
                'choices' => $projectedChoices,
            ];
        }
        return [
            'product' => ['iblockId' => $iblockId, 'productId' => $productId],
            'price' => $this->publicPrice($basePrice, $calculation, false),
            'selection' => $selection->toArray(),
            'groups' => $groups,
        ];
    }

    public function calculate(int $iblockId, int $productId, array $selection): array
    {
        [$configuration, $basePrice] = $this->currentState($iblockId, $productId);
        $normalized = ConfigurationSelection::fromArray($configuration, $selection);
        $calculation = $this->calculator->calculate($configuration, $normalized, $basePrice->priceMinor)->toArray();
        return [
            'product' => ['iblockId' => $iblockId, 'productId' => $productId],
            'selection' => $normalized->toArray(),
            'price' => $this->publicPrice($basePrice, $calculation, true),
        ];
    }

    private function currentState(int $iblockId, int $productId): array
    {
        if ($iblockId <= 0 || $productId <= 0) {
            throw new ConfiguratorException(['code' => 'invalid_request']);
        }
        try {
            $configuration = $this->configurations->get($iblockId, $productId);
        } catch (InvalidArgumentException) {
            throw new ConfiguratorException(['code' => 'product_not_found', 'iblockId' => $iblockId, 'productId' => $productId]);
        }
        return [$configuration, $this->basePrices->get($productId)];
    }

    private function publicPrice(CatalogBasePrice $base, array $calculation, bool $withGroups): array
    {
        $price = [
            'priceTypeId' => $base->priceTypeId,
            'currency' => $base->currency,
            'basePriceMinor' => $calculation['basePriceMinor'],
            'configurationDeltaMinor' => $calculation['configurationDeltaMinor'],
            'finalPriceMinor' => $calculation['finalPriceMinor'],
        ];
        if ($withGroups) {
            $price['groupDeltas'] = array_map(static fn(array $group): int => $group['deltaMinor'], $calculation['groups']);
        }
        return $price;
    }
}
