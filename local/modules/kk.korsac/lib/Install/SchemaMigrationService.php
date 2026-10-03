<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use KK\Korsac\Install\Migration\SimplifyHlSchema;
use KK\Korsac\Install\Migration\PricePrecision;
use KK\Korsac\Install\Migration\OptionImage;

/** Coordinates the one-time v0.1 history baseline with the v0.2 migration. */
final class SchemaMigrationService
{
    public const V01_MIGRATION_ID = '2026_09_29_001_initial_hl_schema';

    public function __construct(
        private readonly MigrationStoreInterface $store,
        private readonly SchemaGatewayInterface $gateway,
    ) {}

    /** @return list<string> */
    public function migrate(): array
    {
        $installer = new SchemaInstaller($this->gateway);
        $migration = new SimplifyHlSchema($this->gateway, $installer);
        $applied = [];

        if (!$this->store->has(self::V01_MIGRATION_ID)) {
            // Migration 001 is historical v0.1 behavior and is deliberately not re-executed.
            // A read-only v0.2 preflight must pass before recording it as a fresh-install baseline.
            $migration->assertSafeToMigrate();
            $this->store->markApplied(self::V01_MIGRATION_ID);
            $applied[] = self::V01_MIGRATION_ID;
        }

        return array_merge($applied, (new MigrationRunner($this->store))->run([
            $migration,
            new PricePrecision($this->gateway),
            new OptionImage($this->gateway),
        ]));
    }
}
