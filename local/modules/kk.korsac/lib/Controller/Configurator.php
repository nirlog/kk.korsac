<?php

declare(strict_types=1);

namespace KK\Korsac\Controller;

use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationException;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Catalog\ProductPresentationRepository;
use KK\Korsac\Configurator\BitrixCatalogPriceProvider;
use KK\Korsac\Configurator\ConfiguratorException;
use KK\Korsac\Configurator\ConfiguratorErrorMapper;
use KK\Korsac\Configurator\ConfiguratorService;
use KK\Korsac\Configurator\HlOptionViewProvider;
use KK\Korsac\Pricing\ConfigurationPricingException;
use KK\Korsac\Pricing\HlOptionPriceProvider;
use KK\Korsac\Pricing\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Pricing\BitrixPricingPolicyProvider;
use KK\Korsac\Repository\OptionRepository;
use Throwable;
use TypeError;

final class Configurator extends Controller
{
    private ?ConfiguratorService $service = null;

    public function configureActions(): array
    {
        return [
            'get' => [
                'prefilters' => [new HttpMethod([HttpMethod::METHOD_GET])],
                '-prefilters' => [Authentication::class],
            ],
            'calculate' => [
                'prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])],
                '-prefilters' => [Authentication::class],
            ],
        ];
    }

    public function getAction(int $iblockId, int $productId): ?array
    {
        return $this->execute(fn(): array => $this->service()->get($iblockId, $productId));
    }

    public function calculateAction(int $iblockId, int $productId, array $selection): ?array
    {
        return $this->execute(fn(): array => $this->service()->calculate($iblockId, $productId, $selection));
    }

    private function service(): ConfiguratorService
    {
        if ($this->service !== null) {
            return $this->service;
        }
        Loader::includeModule('iblock');
        Loader::includeModule('highloadblock');
        Loader::includeModule('catalog');
        $options = new OptionRepository();
        return $this->service = new ConfiguratorService(
            new ProductConfigurationRepository(new BitrixCatalogPropertyGateway(), $options),
            new BitrixCatalogPriceProvider(),
            new HlOptionViewProvider($options),
            new HlOptionPriceProvider($options),
            new ConfiguredCatalogPriceTypeResolver(),
            new BitrixPricingPolicyProvider(),
            new ProductPresentationRepository(new BitrixCatalogPropertyGateway()),
        );
    }

    private function execute(callable $operation): ?array
    {
        try {
            return $operation();
        } catch (ConfiguratorException|ConfigurationPricingException $error) {
            $this->addPublicError($error->diagnostic());
        } catch (ProductConfigurationException) {
            $this->addPublicError(['code' => 'internal_error']);
        } catch (TypeError) {
            $this->addPublicError(['code' => 'invalid_request']);
        } catch (Throwable) {
            $this->addPublicError(['code' => 'internal_error']);
        }
        return null;
    }

    private function addPublicError(array $diagnostic): void
    {
        $error = (new ConfiguratorErrorMapper())->map($diagnostic);
        $this->addError(new Error($error['message'], $error['code'], $error['customData']));
    }
}
