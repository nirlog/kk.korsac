<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use RuntimeException;

final class BitrixCatalogPropertyGateway implements CatalogPropertyGatewayInterface
{
    public const PROPERTY_VALUE_ORDER = ['sort' => 'asc', 'id' => 'asc', 'value_id' => 'asc'];

    public function iblockExists(int $iblockId): bool
    {
        return (bool)\CIBlock::GetByID($iblockId)->Fetch();
    }

    public function find(int $iblockId, string $code): ?array
    {
        $row = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
        if (!$row) {
            return null;
        }
        $settings = $row['USER_TYPE_SETTINGS'] ?? [];
        if (is_string($settings)) {
            $settings = @unserialize($settings, ['allowed_classes' => false]) ?: [];
        }
        $row['USER_TYPE_SETTINGS'] = is_array($settings) ? $settings : [];
        return $row;
    }

    public function create(int $iblockId, array $property): int
    {
        $api = new \CIBlockProperty();
        $id = $api->Add(['IBLOCK_ID' => $iblockId] + $property);
        if (!$id) {
            throw new RuntimeException('Cannot create KORSAC property ' . $property['CODE'] . ': ' . $api->LAST_ERROR);
        }
        return (int)$id;
    }

    public function values(int $iblockId, int $productId, string $code): array
    {
        $result = [];
        $rows = \CIBlockElement::GetProperty($iblockId, $productId, self::PROPERTY_VALUE_ORDER, ['CODE' => $code]);
        while ($row = $rows->Fetch()) {
            $value = trim((string)($row['VALUE'] ?? ''));
            if ($value !== '') {
                $result[] = $value;
            }
        }
        return $result;
    }

    public function enumValues(int $iblockId, int $productId, string $code): array
    {
        $result = [];
        $rows = \CIBlockElement::GetProperty($iblockId, $productId, self::PROPERTY_VALUE_ORDER, ['CODE' => $code]);
        while ($row = $rows->Fetch()) {
            $value = trim((string)($row['VALUE_XML_ID'] ?? ''));
            if ($value !== '') { $result[] = $value; }
        }
        return $result;
    }

    public function enums(int $propertyId): array
    {
        $result = [];
        $rows = \CIBlockPropertyEnum::GetList(['SORT'=>'ASC', 'ID'=>'ASC'], ['PROPERTY_ID'=>$propertyId]);
        while ($row = $rows->Fetch()) {
            $result[] = ['XML_ID'=>(string)$row['XML_ID'], 'VALUE'=>(string)$row['VALUE'], 'DEF'=>(string)$row['DEF']];
        }
        return $result;
    }

    public function productExists(int $iblockId, int $productId): bool
    {
        return (bool)\CIBlockElement::GetList([], ['ID' => $productId, 'IBLOCK_ID' => $iblockId], false, ['nTopCount' => 1], ['ID'])->Fetch();
    }
}
