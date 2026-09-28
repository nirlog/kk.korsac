<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

final class SchemaComparator
{
    public static function fieldIsCompatible(array $actual, array $expected): bool
    {
        if (($actual['USER_TYPE_ID'] ?? null) !== $expected['type']
            || ($actual['MULTIPLE'] ?? 'N') !== ($expected['multiple'] ? 'Y' : 'N')
            || ($actual['MANDATORY'] ?? 'N') !== ($expected['required'] ? 'Y' : 'N')) {
            return false;
        }
        if ($expected['type'] !== 'string' || $expected['length'] === null) {
            return true;
        }
        $settings = $actual['SETTINGS'] ?? [];
        if (is_string($settings)) {
            $settings = unserialize($settings, ['allowed_classes' => false]);
        }
        return is_array($settings) && (int)($settings['MAX_LENGTH'] ?? 0) === $expected['length'];
    }

    public static function indexIsCompatible(array $actual, array $expected): bool
    {
        return self::normalizeIndex($actual) === self::normalizeIndex($expected);
    }

    private static function normalizeIndex(array $index): array
    {
        $columns = [];
        foreach ($index['columns'] ?? [] as $column) {
            $columns[] = [
                'name' => strtoupper((string)($column['name'] ?? '')),
                'length' => isset($column['length']) ? (int)$column['length'] : null,
            ];
        }
        return ['unique' => (bool)($index['unique'] ?? false), 'columns' => $columns];
    }
}
