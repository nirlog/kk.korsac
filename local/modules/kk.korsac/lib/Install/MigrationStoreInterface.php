<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

interface MigrationStoreInterface
{
    public function has(string $migrationId): bool;

    public function markApplied(string $migrationId): void;
}
