<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Entity\DataManager;
use KK\Korsac\Install\SystemSchemaDefinition;
final class SystemTable extends DataManager
{
    public static function getTableName(): string { return SystemSchemaDefinition::SYSTEM; }
    public static function getMap(): array { return SystemSchemaDefinition::ormMap(self::getTableName()); }

    /** Identifier/source immutability also applies to ordinary ORM updates. */
    public static function update($primary, array $data)
    {
        if (array_intersect(array_keys($data), ['SYSTEM_ID','ORDER_ID','ORDER_BASKET_ID','ORDER_UNIT_INDEX'])) throw new SystemException('system_source_immutable');
        return parent::update($primary, $data);
    }
}
