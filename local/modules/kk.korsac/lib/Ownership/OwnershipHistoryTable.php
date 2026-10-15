<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use Bitrix\Main\Entity\DataManager;
use KK\Korsac\Install\OwnershipSchemaDefinition;
use KK\Korsac\System\SystemException;

final class OwnershipHistoryTable extends DataManager
{
    public static function getTableName(): string { return OwnershipSchemaDefinition::HISTORY; }
    public static function getMap(): array { return OwnershipSchemaDefinition::ormMap(self::getTableName()); }
    public static function update($primary,array $data) { throw new SystemException('system_ownership_history_immutable'); }
    public static function delete($primary) { throw new SystemException('system_ownership_history_immutable'); }
}
