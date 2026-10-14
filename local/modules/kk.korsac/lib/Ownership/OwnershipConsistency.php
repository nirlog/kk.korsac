<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use KK\Korsac\System\SystemException;

final class OwnershipConsistency
{
    public static function assertCurrent(OwnershipSystemState $system,?OwnershipHistoryRecord $latest): void
    {
        $system->assertValid();
        if ($latest!==null) {
            $latest->assertValid();
            if ($latest->id<1 || $latest->systemInternalId!==$system->systemInternalId) throw new SystemException('system_ownership_state_invalid');
        }
        if (($system->userId===null && $latest!==null) || ($system->userId!==null && ($latest===null || $latest->toUserId!==$system->userId))) throw new SystemException('system_ownership_state_invalid');
    }
}
