<?php

declare(strict_types=1);

namespace KK\Korsac\Admin;

use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Catalog\GroupTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Main\Config\Option;
use KK\Korsac\Pricing\PricingConfiguration;

final class BitrixPricingAdminGateway implements PricingAdminGatewayInterface
{
    public function catalogExists(int $iblockId): bool
    {
        return $iblockId > 0 && (bool)CatalogIblockTable::getByPrimary($iblockId, ['select' => ['IBLOCK_ID']])->fetch();
    }

    public function priceTypeExists(int $priceTypeId): bool
    {
        return $priceTypeId > 0 && (bool)GroupTable::getByPrimary($priceTypeId, ['select' => ['ID']])->fetch();
    }

    public function readOption(string $key): ?string
    {
        $value = Option::get(PricingConfiguration::MODULE_ID, $key, '');
        return $value === '' ? null : $value;
    }

    public function writeOption(string $key, string $value): void
    {
        Option::set(PricingConfiguration::MODULE_ID, $key, $value);
    }

    public function removeOption(string $key): void
    {
        Option::delete(PricingConfiguration::MODULE_ID, ['name' => $key]);
    }

    public function catalogs(): array
    {
        $result = [];
        $rows = CatalogIblockTable::getList(['select' => ['IBLOCK_ID'], 'order' => ['IBLOCK_ID' => 'ASC']]);
        while ($catalog = $rows->fetch()) {
            $id = (int)$catalog['IBLOCK_ID'];
            $iblock = IblockTable::getByPrimary($id, ['select' => ['NAME']])->fetch();
            if ($iblock) {
                $result[$id] = (string)$iblock['NAME'];
            }
        }
        return $result;
    }

    public function priceTypes(): array
    {
        $result = [];
        $rows = GroupTable::getList(['select' => ['ID', 'NAME'], 'order' => ['ID' => 'ASC']]);
        while ($row = $rows->fetch()) {
            $result[(int)$row['ID']] = (string)$row['NAME'];
        }
        return $result;
    }
}
