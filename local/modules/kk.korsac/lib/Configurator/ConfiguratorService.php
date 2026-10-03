<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Catalog\ProductPresentationRepository;
use KK\Korsac\Pricing\ConfigurationSelection;
use KK\Korsac\Pricing\ConfigurationPriceCalculator;
use KK\Korsac\Pricing\CatalogPriceTypeResolverInterface;
use KK\Korsac\Pricing\OptionPriceProviderInterface;
use KK\Korsac\Pricing\PricingPolicyProviderInterface;
use KK\Korsac\Pricing\RetailOptionPriceProvider;

final class ConfiguratorService
{
    private readonly ConfiguredProductPricingService $pricing;

    public function __construct(
        private readonly ProductConfigurationRepository $configurations,
        private readonly CatalogPriceProviderInterface $catalogPrices,
        private readonly OptionViewProviderInterface $optionViews,
        private readonly OptionPriceProviderInterface $rawOptionPrices,
        private readonly CatalogPriceTypeResolverInterface $priceTypes,
        private readonly PricingPolicyProviderInterface $policies,
        private readonly ProductPresentationRepository $presentations,
    ) {
        $this->pricing = new ConfiguredProductPricingService($configurations, $catalogPrices, $rawOptionPrices, $priceTypes, $policies);
    }

    public function get(int $iblockId, int $productId): array
    {
        $quote = $this->pricing->quote($iblockId, $productId, []);
        $configuration = $quote->configuration;
        $selection = $quote->selection;
        $calculator = new ConfigurationPriceCalculator(new RetailOptionPriceProvider(
            $this->rawOptionPrices,
            $this->policies->get($iblockId, $quote->priceTypeId),
        ));
        $presentation = $this->presentations->get($iblockId, $productId);
        $groups = [];
        foreach ($configuration->toArray() as $group => $definition) {
            $choices = $definition['mode'] === 'single'
                ? array_values(array_filter(array_merge([$definition['default']], $definition['options']), static fn(mixed $id): bool => is_string($id)))
                : $definition['options'];
            $projectedChoices = [];
            foreach ($choices as $xmlId) {
                $candidate = ConfigurationSelection::fromArray($configuration, [$group => $definition['mode'] === 'single' ? $xmlId : [$xmlId]]);
                $candidateResult = $calculator->calculate($configuration, $candidate, $quote->basePriceMinor)->toArray();
                $view = $this->optionViews->get($group, $xmlId);
                $projectedChoices[] = [
                    'xmlId' => $view->xmlId,
                    'name' => $view->name,
                    'description' => $view->description,
                    'image' => $view->image,
                    'deltaMinor' => $candidateResult['groups'][$group]['deltaMinor'],
                ];
            }
            $groups[$group] = [
                'mode' => $definition['mode'],
                'default' => $definition['mode'] === 'single' ? $definition['default'] : [],
                ...($definition['mode'] === 'single' ? ['allowNull' => $definition['default'] === null] : []),
                'presentation' => ['mode'=>$presentation->mode($group)],
                'choices' => $projectedChoices,
            ];
        }
        return [
            'product' => ['iblockId' => $iblockId, 'productId' => $productId],
            'price' => array_diff_key($quote->publicPrice(), ['groupDeltas' => true]),
            'selection' => $selection->toArray(),
            'groups' => $groups,
        ];
    }

    public function calculate(int $iblockId, int $productId, array $selection): array
    {
        $quote = $this->pricing->quote($iblockId, $productId, $selection);
        return [
            'product' => ['iblockId' => $iblockId, 'productId' => $productId],
            'selection' => $quote->selection->toArray(),
            'price' => $quote->publicPrice(),
        ];
    }
}
