<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Entity\DataManager;
use KK\Korsac\Install\PassportSchemaDefinition;
final class PassportTable extends DataManager
{
    public static function getTableName(): string { return PassportSchemaDefinition::TABLE; }
    public static function getMap(): array { return PassportSchemaDefinition::ormMap(self::getTableName()); }
    /** Only the controlled repository's fixed conditional activation SQL can mutate this table. */
    public static function update($primary,array $data) { throw new SystemException('system_passport_immutable'); }
    public static function delete($primary) { throw new SystemException('system_passport_immutable'); }
}
