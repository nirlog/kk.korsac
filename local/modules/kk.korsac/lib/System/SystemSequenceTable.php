<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Entity\DataManager;
use KK\Korsac\Install\SystemSchemaDefinition;
final class SystemSequenceTable extends DataManager
{
    public static function getTableName(): string { return SystemSchemaDefinition::SEQUENCE; }
    public static function getMap(): array { return SystemSchemaDefinition::ormMap(self::getTableName()); }
}
