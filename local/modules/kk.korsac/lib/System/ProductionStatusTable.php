<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Entity\DataManager;
use KK\Korsac\Install\ProductionStatusSchemaDefinition;
final class ProductionStatusTable extends DataManager
{
    public static function getTableName(): string { return ProductionStatusSchemaDefinition::CURRENT; }
    public static function getMap(): array { return ProductionStatusSchemaDefinition::ormMap(self::getTableName()); }
}
