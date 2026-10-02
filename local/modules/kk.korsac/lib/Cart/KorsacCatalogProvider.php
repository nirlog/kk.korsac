<?php

declare(strict_types=1);

namespace KK\Korsac\Cart;

use Bitrix\Catalog\Product\CatalogProvider;
use Bitrix\Main\Error;
use Bitrix\Sale\Internals\BasketPropertyTable;
use Bitrix\Sale\Result;
use Throwable;

/** Preserves snapshot-authoritative prices while delegating all other catalog data to Bitrix. */
final class KorsacCatalogProvider extends CatalogProvider
{
    public function getProductData(array $products): Result
    {
        $result = parent::getProductData($products);
        if (!$result->isSuccess()) {
            return $result;
        }

        $data = $result->getData();
        $projector = new ConfiguredBasketPriceProjector(new BitrixConfigurationSnapshotRepository());
        foreach ($products as $basketCode => $product) {
            $properties = $this->properties((array)$product, $basketCode);
            if (($properties['KORSAC_CONFIGURED'] ?? null) !== 'Y') {
                continue;
            }
            $snapshotKey = trim((string)($properties['KORSAC_SNAPSHOT_KEY'] ?? ''));
            if ($snapshotKey === '') {
                $result->addError(new Error('KORSAC configuration snapshot is invalid', 'snapshot_invalid'));
                continue;
            }
            $priceData = $data['PRODUCT_DATA_LIST'][$basketCode] ?? [];
            $currency = (string)($product['CURRENCY'] ?? $priceData['CURRENCY'] ?? '');
            try {
                $projection = $projector->project($snapshotKey, (int)($product['PRODUCT_ID'] ?? 0), $currency);
                $data['PRODUCT_DATA_LIST'][$basketCode] = array_replace($priceData, $projection);
                if (isset($data['PRODUCT_DATA_LIST_FULL'][$basketCode])) {
                    $data['PRODUCT_DATA_LIST_FULL'][$basketCode] = array_replace($data['PRODUCT_DATA_LIST_FULL'][$basketCode], $projection);
                }
            } catch (CartException $error) {
                $result->addError(new Error('KORSAC configuration snapshot validation failed', $error->diagnostic()['code'] ?? 'snapshot_invalid'));
            } catch (Throwable) {
                $result->addError(new Error('KORSAC configuration snapshot validation failed', 'snapshot_invalid'));
            }
        }
        $result->setData($data);
        return $result;
    }

    private function properties(array $product, int|string $basketCode): array
    {
        foreach (['PROPS', 'PROPERTIES'] as $field) {
            if (isset($product[$field]) && is_array($product[$field])) {
                $values = $this->normalizeProperties($product[$field]);
                if ($values !== []) return $values;
            }
        }
        $basketId = (int)($product['BASKET_ID'] ?? $product['ID'] ?? $basketCode);
        if ($basketId <= 0) return [];
        $query = BasketPropertyTable::getList(['select'=>['CODE','VALUE'], 'filter'=>['=BASKET_ID'=>$basketId]]);
        $rows = [];
        while ($row = $query->fetch()) $rows[] = $row;
        return $this->normalizeProperties($rows);
    }

    private function normalizeProperties(array $properties): array
    {
        $normalized = [];
        foreach ($properties as $key => $property) {
            if (is_array($property)) {
                $code = (string)($property['CODE'] ?? $key);
                $value = $property['VALUE'] ?? null;
            } else {
                $code = (string)$key;
                $value = $property;
            }
            if ($code !== '' && is_scalar($value)) $normalized[$code] = (string)$value;
        }
        return $normalized;
    }
}
