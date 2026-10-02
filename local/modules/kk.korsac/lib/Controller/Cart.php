<?php

declare(strict_types=1);

namespace KK\Korsac\Controller;

use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use KK\Korsac\Cart\BasketPropertyProjector;
use KK\Korsac\Cart\BitrixBasketGateway;
use KK\Korsac\Cart\BitrixConfigurationSnapshotRepository;
use KK\Korsac\Cart\BitrixProductViewProvider;
use KK\Korsac\Cart\BitrixSiteResolver;
use KK\Korsac\Cart\CartErrorMapper;
use KK\Korsac\Cart\CartException;
use KK\Korsac\Cart\ConfigurationSnapshotBuilder;
use KK\Korsac\Cart\ConfiguredProductCartService;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationException;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Configurator\BitrixCatalogPriceProvider;
use KK\Korsac\Configurator\ConfiguratorException;
use KK\Korsac\Configurator\HlOptionViewProvider;
use KK\Korsac\Pricing\BitrixPricingPolicyProvider;
use KK\Korsac\Pricing\ConfigurationPricingException;
use KK\Korsac\Pricing\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Configurator\ConfiguredProductPricingService;
use KK\Korsac\Pricing\HlOptionPriceProvider;
use KK\Korsac\Repository\OptionRepository;
use Throwable;
use TypeError;

final class Cart extends Controller
{
    private ?ConfiguredProductCartService $service = null;

    public function configureActions(): array
    {
        return ['add'=>['prefilters'=>[new HttpMethod([HttpMethod::METHOD_POST])],'-prefilters'=>[Authentication::class]]];
    }

    public function addAction(int $iblockId, int $productId, array $selection): ?array
    {
        try { return $this->service()->add($iblockId,$productId,$selection); }
        catch (CartException|ConfiguratorException|ConfigurationPricingException $error) { $this->addPublicError($error->diagnostic()); }
        catch (ProductConfigurationException) { $this->addPublicError(['code'=>'internal_error']); }
        catch (TypeError) { $this->addPublicError(['code'=>'invalid_request']); }
        catch (Throwable) { $this->addPublicError(['code'=>'internal_error']); }
        return null;
    }

    private function service(): ConfiguredProductCartService
    {
        if ($this->service) return $this->service;
        foreach (['iblock','highloadblock','catalog'] as $module) Loader::includeModule($module);
        if (!Loader::includeModule('sale')) throw new CartException(['code'=>'sale_module_not_available']);
        $options=new OptionRepository();
        $pricing=new ConfiguredProductPricingService(
            new ProductConfigurationRepository(new BitrixCatalogPropertyGateway(),$options),new BitrixCatalogPriceProvider(),
            new HlOptionPriceProvider($options),new ConfiguredCatalogPriceTypeResolver(),new BitrixPricingPolicyProvider(),
        );
        return $this->service=new ConfiguredProductCartService(
            $pricing,new ConfigurationSnapshotBuilder(new HlOptionViewProvider($options)),new BitrixConfigurationSnapshotRepository(),
            new BitrixBasketGateway(),new BasketPropertyProjector(),new BitrixSiteResolver(),new BitrixProductViewProvider(),
        );
    }

    private function addPublicError(array $diagnostic): void
    {
        $error=(new CartErrorMapper())->map($diagnostic);
        $this->addError(new Error($error['message'],$error['code'],$error['customData']));
    }
}
