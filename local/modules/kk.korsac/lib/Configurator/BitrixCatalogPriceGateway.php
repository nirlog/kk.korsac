<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

use Bitrix\Catalog\PriceTable;

final class BitrixCatalogPriceGateway implements CatalogPriceGatewayInterface
{
    public function findPrice(int $productId, int $priceTypeId): ?array
    {
        $row = PriceTable::getList([
            'select' => ['PRICE', 'CURRENCY'],
            'filter' => ['=PRODUCT_ID' => $productId, '=CATALOG_GROUP_ID' => $priceTypeId],
            'limit' => 1,
        ])->fetch();
        return $row ? ['priceTypeId' => $priceTypeId, 'price' => $row['PRICE'], 'currency' => (string)$row['CURRENCY']] : null;
    }
}
