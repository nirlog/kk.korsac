<?php

declare(strict_types=1);

namespace KK\Korsac\Health;
use KK\Korsac\Install\OwnershipSchemaDefinition;
use KK\Korsac\Install\SystemTableGatewayInterface;
use Throwable;
final class OwnershipSchemaSelfCheck
{
    public function __construct(private readonly SystemTableGatewayInterface $gateway) {}
    public function run(): array
    {
        $errors = [];
        foreach (OwnershipSchemaDefinition::tables() as $table=>$definition) {
            try { $actual = $this->gateway->describe($table); }
            catch (Throwable) { $errors[] = ['code'=>'ownership_schema_read_failed','table'=>$table]; continue; }
            $errors = array_merge($errors, self::compare($table, $actual));
        }
        return ['ok'=>$errors === [], 'errors'=>$errors];
    }
    public static function compare(string $table, ?array $actual, ?array $definition = null): array
    {
        if ($actual === null) return [['code'=>'ownership_table_missing','table'=>$table]];
        $errors = [];
        if (strcasecmp($actual['engine'] ?? '', 'InnoDB') !== 0) $errors[] = ['code'=>'ownership_engine_mismatch','table'=>$table];
        $expected = $definition ?? OwnershipSchemaDefinition::tables()[$table];
        foreach ($expected['columns'] as $name=>$column) {
            $field = $actual['columns'][$name] ?? null;
            $type = strtolower((string)($field['type'] ?? ''));
            // MySQL 8 omits legacy integer display widths; MariaDB may retain them.
            $type = preg_replace('/\b(int|bigint)\([0-9]+\)/', '$1', $type);
            if ($field === null || $type !== $column['type'] || ($field['nullable'] ?? null) !== $column['nullable'] || !empty($field['auto']) !== !empty($column['auto']) || (isset($column['size']) && !str_starts_with((string)($field['collation'] ?? ''), 'utf8mb4_'))) {
                $errors[] = ['code'=>$field === null ? 'ownership_column_missing' : 'ownership_column_mismatch','table'=>$table,'field'=>$name];
            }
        }
        foreach ($expected['indexes'] as $name=>$index) {
            $found = $actual['indexes'][$name] ?? null;
            if ($found === null || ($found['columns'] ?? []) !== $index['columns'] || ($found['unique'] ?? null) !== $index['unique'] || !empty($found['partial'])) {
                $errors[] = ['code'=>$found === null ? 'ownership_index_missing' : 'ownership_index_mismatch','table'=>$table,'index'=>$name];
            }
        }
        return $errors;
    }
}
