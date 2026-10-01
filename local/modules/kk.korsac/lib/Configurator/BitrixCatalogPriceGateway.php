<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\PriceTable;

final class BitrixCatalogPriceGateway implements CatalogPriceGatewayInterface
{
    public function findBasePrice(int $productId): ?array
    {
        $priceType = GroupTable::getList(['select' => ['ID'], 'filter' => ['=BASE' => 'Y'], 'order' => ['ID' => 'ASC'], 'limit' => 1])->fetch();
        if (!$priceType) {
            return null;
        }
        $row = PriceTable::getList(['select' => ['PRICE', 'CURRENCY'], 'filter' => ['=PRODUCT_ID' => $productId, '=CATALOG_GROUP_ID' => (int)$priceType['ID']], 'limit' => 1])->fetch();
        return $row ? ['priceTypeId' => (int)$priceType['ID'], 'price' => $row['PRICE'], 'currency' => (string)$row['CURRENCY']] : null;
    }
}
