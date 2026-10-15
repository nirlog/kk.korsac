<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
use KK\Korsac\Install\Migration\SystemOwnershipCore;
final class OwnershipMigrationService
{
    public function __construct(private readonly MigrationStoreInterface $store,private readonly OwnershipTableGatewayInterface $gateway) {}
    public function migrate(): array { return (new MigrationRunner($this->store))->run([new SystemOwnershipCore($this->gateway)]); }
}
