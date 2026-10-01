<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
use KK\Korsac\Configurator\ConfiguredProductQuote;
interface BasketGatewayInterface
{
    public function addConfiguredProduct(ConfiguredProductQuote $quote, string $siteId, string $productName, array $properties): BasketResult;
}
