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

        $context = $this->getContext();
        $currency = strtoupper(trim((string)($context['CURRENCY'] ?? '')));
        try {
            $data = (new ConfiguredProviderDataProjector(new ConfiguredBasketPriceProjector(new BitrixConfigurationSnapshotRepository())))
                ->project($result->getData(), $products, $currency, fn(array $product, int|string $basketCode): array => $this->properties($product, $basketCode));
            $result->setData($data);
        } catch (CartException $error) {
            $result->addError(new Error('KORSAC configuration snapshot validation failed', $error->diagnostic()['code'] ?? 'snapshot_invalid'));
        } catch (Throwable) {
            $result->addError(new Error('KORSAC configuration snapshot validation failed', 'snapshot_invalid'));
        }
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
