<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Entity\DataManager;
use KK\Korsac\Install\SystemSchemaDefinition;
final class SystemComponentTable extends DataManager
{
    public static function getTableName(): string { return SystemSchemaDefinition::COMPONENT; }
    public static function getMap(): array { return SystemSchemaDefinition::ormMap(self::getTableName()); }
}
