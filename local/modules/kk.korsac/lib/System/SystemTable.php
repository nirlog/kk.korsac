<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Entity\DataManager;
use KK\Korsac\Install\SystemSchemaDefinition;
final class SystemTable extends DataManager
{
    public static function getTableName(): string { return SystemSchemaDefinition::SYSTEM; }
    public static function getMap(): array { return SystemSchemaDefinition::ormMap(self::getTableName()); }

    // Every other column is immutable through ordinary ORM updates, including future source fields.
    private const MUTABLE_FIELDS = ['LIFECYCLE_STATE', 'UPDATED_AT'];

    /** Normal physical creation remains unowned; assignment requires the ownership audit transaction. */
    public static function add(array $data)
    {
        if (($data['USER_ID'] ?? null)!==null) throw new SystemException('system_source_immutable');
        return parent::add($data);
    }

    /** Preserve the full historical creation identity/source snapshot. */
    public static function update($primary, array $data)
    {
        if (array_diff(array_keys($data), self::MUTABLE_FIELDS)) throw new SystemException('system_source_immutable');
        return parent::update($primary, $data);
    }
}
