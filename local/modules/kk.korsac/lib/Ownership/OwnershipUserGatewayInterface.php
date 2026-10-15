<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

interface OwnershipUserGatewayInterface
{
    /** Current standalone Bitrix user validation within the claim transaction. */
    public function isActiveUser(int $userId): bool;
}
