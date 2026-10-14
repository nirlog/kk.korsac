<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use KK\Korsac\System\SystemException;

final class OwnershipRevision
{
    /** Only used under the parent lock; v1 consistent unowned state has no history. */
    public static function next(?OwnershipHistoryRecord $latest): int
    {
        if ($latest===null) return 1;
        $latest->assertValid();
        if ($latest->revision===OwnershipIdentity::MAX) throw new SystemException('system_ownership_revision_exhausted');
        return $latest->revision+1;
    }
}
