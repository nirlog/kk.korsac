<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use InvalidArgumentException;

final class SqlIndexBuilder
{
    public static function create(string $tableName, string $indexName, array $columns, bool $unique): string
    {
        self::assertIdentifier($tableName);
        self::assertIdentifier($indexName);
        if ($columns === []) {
            throw new InvalidArgumentException('An index must contain at least one column.');
        }
        $sqlColumns = [];
        foreach ($columns as $column) {
            $columnName = (string)($column['name'] ?? '');
            self::assertIdentifier($columnName);
            $length = $column['length'] ?? null;
            if ($length !== null && (!is_int($length) || $length < 1)) {
                throw new InvalidArgumentException("Invalid index prefix length for {$columnName}");
            }
            $sqlColumns[] = "`{$columnName}`" . ($length !== null ? "({$length})" : '');
        }
        $uniqueSql = $unique ? 'UNIQUE ' : '';
        return "CREATE {$uniqueSql}INDEX `{$indexName}` ON `{$tableName}` (" . implode(', ', $sqlColumns) . ')';
    }

    public static function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }
    }
}
