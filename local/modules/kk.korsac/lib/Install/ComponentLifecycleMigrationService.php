<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use KK\Korsac\Health\SystemComponentDataHealth;
use KK\Korsac\Install\Migration\SystemComponentLifecycleCore;

final class ComponentLifecycleMigrationService
{
    public function __construct(private readonly MigrationStoreInterface $store,private readonly SystemComponentDataHealth $health) {}
    public function migrate(): array { return (new MigrationRunner($this->store))->run([new SystemComponentLifecycleCore($this->health)]); }
}
