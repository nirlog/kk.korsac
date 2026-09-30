<?php

declare(strict_types=1);

namespace KK\Korsac\Install\Migration;

use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\SchemaInstaller;

final class InitialHlSchema implements MigrationInterface
{
    public function __construct(private readonly SchemaInstaller $installer) {}
    public function id(): string { return '2026_09_29_001_initial_hl_schema'; }
    public function up(): void { $this->installer->install(); }
}
