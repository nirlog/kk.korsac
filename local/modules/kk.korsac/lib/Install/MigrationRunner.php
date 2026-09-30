<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

final class MigrationRunner
{
    public function __construct(private readonly MigrationStoreInterface $store)
    {
    }

    /** @param iterable<MigrationInterface> $migrations */
    public function run(iterable $migrations): array
    {
        $applied = [];
        foreach ($migrations as $migration) {
            if ($this->store->has($migration->id())) {
                continue;
            }
            $migration->up();
            $this->store->markApplied($migration->id());
            $applied[] = $migration->id();
        }

        return $applied;
    }
}
