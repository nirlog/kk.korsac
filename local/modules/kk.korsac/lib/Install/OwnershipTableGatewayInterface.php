<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

interface OwnershipTableGatewayInterface extends SystemTableGatewayInterface
{
    public function legacyOwnerCount(): int;
}
