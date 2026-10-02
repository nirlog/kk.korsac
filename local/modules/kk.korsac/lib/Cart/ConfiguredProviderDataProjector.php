<?php

declare(strict_types=1);

namespace KK\Korsac\Cart;

final class ConfiguredProviderDataProjector
{
    public function __construct(private readonly ConfiguredBasketPriceProjector $prices) {}

    /** @param callable(array,int|string):array $propertyResolver */
    public function project(array $data, array $products, string $currency, callable $propertyResolver): array
    {
        foreach ($products as $inputKey => $product) {
            $product = (array)$product;
            $productId = (int)($product['PRODUCT_ID'] ?? (is_numeric($inputKey) ? $inputKey : 0));
            foreach ($this->basketCodes($inputKey, $product) as $basketCode) {
                $properties = $propertyResolver($product, $basketCode);
                if (($properties['KORSAC_CONFIGURED'] ?? null) !== 'Y') continue;
                $snapshotKey = trim((string)($properties['KORSAC_SNAPSHOT_KEY'] ?? ''));
                if ($snapshotKey === '') throw new CartException(['code'=>'snapshot_invalid']);
                $projection = $this->prices->project($snapshotKey, $productId, $currency);
                $projected = false;
                foreach (['PRODUCT_DATA_LIST','PRODUCT_DATA_LIST_FULL'] as $list) {
                    if (isset($data[$list][$productId]['PRICE_LIST'][$basketCode]) && is_array($data[$list][$productId]['PRICE_LIST'][$basketCode])) {
                        $data[$list][$productId]['PRICE_LIST'][$basketCode] = array_replace($data[$list][$productId]['PRICE_LIST'][$basketCode], $projection);
                        $projected = true;
                    } elseif (isset($data[$list][$basketCode]) && is_array($data[$list][$basketCode]) && $this->isDirectPriceRow($data[$list][$basketCode])) {
                        $data[$list][$basketCode] = array_replace($data[$list][$basketCode], $projection);
                        $projected = true;
                    }
                }
                if (!$projected) throw new CartException(['code'=>'snapshot_invalid']);
            }
        }
        return $data;
    }

    private function basketCodes(int|string $inputKey, array $product): array
    {
        $codes = [];
        if (isset($product['BASKET_CODE']) && is_scalar($product['BASKET_CODE'])) $codes[] = $product['BASKET_CODE'];
        foreach (['QUANTITY_LIST','PRICE_LIST'] as $field) {
            if (isset($product[$field]) && is_array($product[$field])) $codes = array_merge($codes, array_keys($product[$field]));
        }
        if ($codes === [] && isset($product['PRODUCT_ID'])) $codes[] = $inputKey;
        return array_values(array_unique($codes, SORT_REGULAR));
    }

    private function isDirectPriceRow(array $row): bool
    {
        return array_key_exists('PRICE',$row) || array_key_exists('BASE_PRICE',$row);
    }
}
