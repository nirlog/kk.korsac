<?php
declare(strict_types=1);
namespace KK\Korsac\Install;
interface SnapshotTableGatewayInterface
{
    public function tableExists(string $table): bool;
    public function createTable(string $table): void;
    public function indexExists(string $table,string $index): bool;
    public function createIndex(string $table,string $index,array $columns,bool $unique): void;
}
