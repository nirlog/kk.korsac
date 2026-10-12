<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
interface SystemTableGatewayInterface
{
    /** null if absent, otherwise engine/columns/indexes matching schema metadata. */
    public function describe(string $table): ?array;
    public function createTable(string $table): void;
    public function createIndex(string $table, string $name, array $index): void;
}
