<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

use KK\Korsac\Pricing\ConfigurationPricingException;
use KK\Korsac\Pricing\PriceNormalizer;

final class BitrixCatalogPriceProvider implements CatalogPriceProviderInterface
{
    public function __construct(private readonly CatalogPriceGatewayInterface $gateway = new BitrixCatalogPriceGateway()) {}

    public function get(int $productId, int $priceTypeId): CatalogPrice
    {
        $row = $this->gateway->findPrice($productId, $priceTypeId);
        if ($row === null || !array_key_exists('price', $row)) {
            throw new ConfiguratorException(['code' => 'catalog_price_not_found', 'productId' => $productId, 'priceTypeId' => $priceTypeId]);
        }
        $currency = strtoupper(trim((string)($row['currency'] ?? '')));
        if ($currency !== 'RUB') {
            throw new ConfiguratorException(['code' => 'unsupported_catalog_currency', 'currency' => $currency]);
        }
        try {
            $minor = PriceNormalizer::toMinor($row['price']);
        } catch (ConfigurationPricingException) {
            throw new ConfiguratorException(['code' => 'catalog_price_not_found', 'productId' => $productId, 'priceTypeId' => $priceTypeId]);
        }
        if ($minor < 0) {
            throw new ConfiguratorException(['code' => 'catalog_price_not_found', 'productId' => $productId, 'priceTypeId' => $priceTypeId]);
        }
        return new CatalogPrice((int)$row['priceTypeId'], $currency, $minor);
    }
}
