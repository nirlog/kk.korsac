<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Entity\DataManager;
use KK\Korsac\Install\ProductionStatusSchemaDefinition;
final class ProductionStatusHistoryTable extends DataManager
{
    public static function getTableName(): string { return ProductionStatusSchemaDefinition::HISTORY; }
    public static function getMap(): array { return ProductionStatusSchemaDefinition::ormMap(self::getTableName()); }

    public static function update($primary, array $data) { throw new SystemException('system_production_history_immutable'); }
    public static function delete($primary) { throw new SystemException('system_production_history_immutable'); }
}
