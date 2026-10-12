<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
use KK\Korsac\Install\Migration\SystemCore;
final class SystemMigrationService
{
    public function __construct(private readonly MigrationStoreInterface $store, private readonly SystemTableGatewayInterface $gateway) {}
    public function migrate(): array { return (new MigrationRunner($this->store))->run([new SystemCore($this->gateway)]); }
}
