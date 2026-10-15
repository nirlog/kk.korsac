<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Entity\DataManager;
use KK\Korsac\Install\SystemSchemaDefinition;
final class SystemComponentTable extends DataManager
{
    public static function getTableName(): string { return SystemSchemaDefinition::COMPONENT; }
    public static function getMap(): array { return SystemSchemaDefinition::ormMap(self::getTableName()); }
    public static function add(array $data) { throw new SystemException('system_component_immutable'); }
    public static function addMulti($rows,$ignoreEvents=false) { throw new SystemException('system_component_immutable'); }
    public static function update($primary,array $data) { throw new SystemException('system_component_immutable'); }
    public static function updateMulti($primaries,$data,$ignoreEvents=false) { throw new SystemException('system_component_immutable'); }
    public static function delete($primary) { throw new SystemException('system_component_immutable'); }
}
