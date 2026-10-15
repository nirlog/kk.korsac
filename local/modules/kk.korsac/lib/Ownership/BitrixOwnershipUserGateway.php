<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use Bitrix\Main\Application;
use Bitrix\Main\UserTable;
use KK\Korsac\System\SystemException;
use Throwable;

final class BitrixOwnershipUserGateway implements OwnershipUserGatewayInterface
{
    public function isActiveUser(int $userId): bool
    {
        if (!OwnershipIdentity::valid($userId)) return false;
        try {
            $table=UserTable::getTableName();
            // Current locking read inside the claim transaction; concurrent deactivation waits until commit.
            return (bool)Application::getConnection()->query("SELECT `ID` FROM `{$table}` WHERE `ID`={$userId} AND `ACTIVE`='Y' FOR UPDATE")->fetch();
        } catch (Throwable) { throw new SystemException('system_ownership_read_failed'); }
    }
}
