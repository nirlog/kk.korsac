<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
use Bitrix\Main\Loader;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;
use KK\Korsac\Configurator\ConfiguredProductQuote;
final class BitrixBasketGateway implements BasketGatewayInterface
{
    public function addConfiguredProduct(ConfiguredProductQuote $quote, string $siteId, string $productName, array $properties): BasketResult
    {
        if (!Loader::includeModule('sale')) throw new CartException(['code'=>'sale_module_not_available']);
        $basket=Basket::loadItemsForFUser(Fuser::getId(), $siteId);
        if (!$basket) throw new CartException(['code'=>'basket_unavailable']);
        $item=$basket->createItem('catalog', $quote->productId);
        $result=$item->setFields([
            'QUANTITY'=>1, 'CURRENCY'=>$quote->currency, 'LID'=>$siteId, 'PRODUCT_ID'=>$quote->productId,
            'NAME'=>$productName, 'PRICE'=>MinorUnitFormatter::decimal($quote->finalPriceMinor), 'CUSTOM_PRICE'=>'Y',
            'PRODUCT_PROVIDER_CLASS'=>'CCatalogProductProvider',
        ]);
        if (!$result->isSuccess()) throw new CartException(['code'=>'basket_save_failed']);
        $propertyResult=$item->getPropertyCollection()->setProperty($properties);
        if (!$propertyResult->isSuccess()) throw new CartException(['code'=>'basket_save_failed']);
        $save=$basket->save();
        if (!$save->isSuccess() || (int)$item->getId() <= 0) throw new CartException(['code'=>'basket_save_failed']);
        return new BasketResult((int)$item->getId());
    }
}
