<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

interface SchemaGatewayInterface
{
    public function getBlock(string $name): ?array;
    public function getBlockByTable(string $tableName): ?array;
    public function createBlock(string $name, string $tableName): array;
    /** @return array<string, array> */
    public function getFields(int $blockId): array;
    public function createField(int $blockId, array $field): void;
    /** @return array<string, array{unique: bool, columns: list<string>}> */
    public function getIndexes(string $tableName): array;
    public function findDuplicateValues(string $tableName, string $column): array;
    public function createIndex(string $tableName, string $name, array $columns, bool $unique): void;
    public function rows(string $blockName, array $select = ['*']): array;
}
