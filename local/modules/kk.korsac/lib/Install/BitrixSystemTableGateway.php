<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
use Bitrix\Main\Application;
use KK\Korsac\System\SystemException;
use Throwable;
final class BitrixSystemTableGateway implements SystemTableGatewayInterface
{
    private object $connection;
    public function __construct() { $this->connection = Application::getConnection(); }
    public function describe(string $table): ?array
    {
        if (!isset(SystemSchemaDefinition::tables()[$table])) throw new SystemException('system_schema_invalid');
        try {
            if (!$this->connection->isTableExists($table)) return null;
            $status = $this->connection->query("SHOW TABLE STATUS WHERE Name='{$table}'")->fetch();
            $description = ['engine'=>$status['Engine'] ?? '', 'columns'=>[], 'indexes'=>[]];
            $rows = $this->connection->query("SHOW FULL COLUMNS FROM `{$table}`");
            while ($row = $rows->fetch()) {
                $description['columns'][$row['Field']] = [
                    'type'=>strtolower((string)$row['Type']), 'nullable'=>$row['Null'] === 'YES',
                    'auto'=>str_contains(strtolower((string)$row['Extra']), 'auto_increment'),
                    'collation'=>$row['Collation'],
                ];
            }
            $rows = $this->connection->query("SHOW INDEX FROM `{$table}`");
            while ($row = $rows->fetch()) {
                $name = $row['Key_name'];
                $description['indexes'][$name]['unique'] = (int)$row['Non_unique'] === 0;
                $description['indexes'][$name]['columns'][(int)$row['Seq_in_index']] = $row['Column_name'];
                if ($row['Sub_part'] !== null || ($row['Collation'] ?? 'A') !== 'A') $description['indexes'][$name]['partial'] = true;
            }
            foreach ($description['indexes'] as &$index) { ksort($index['columns']); $index['columns'] = array_values($index['columns']); }
            return $description;
        } catch (Throwable) { throw new SystemException('system_schema_read_failed'); }
    }
    public function createTable(string $table): void
    {
        try { $this->connection->queryExecute(SystemSchemaDefinition::createSql($table)); }
        catch (Throwable) { throw new SystemException('system_schema_write_failed'); }
    }
    public function createIndex(string $table, string $name, array $index): void
    {
        if ((SystemSchemaDefinition::tables()[$table]['indexes'][$name] ?? null) !== $index || $name === 'PRIMARY') throw new SystemException('system_schema_invalid');
        $columns = implode(',', array_map(static fn(string $column): string => "`{$column}`", $index['columns']));
        $kind = $index['unique'] ? 'UNIQUE INDEX' : 'INDEX';
        try { $this->connection->queryExecute("CREATE {$kind} `{$name}` ON `{$table}` ({$columns})"); }
        catch (Throwable) { throw new SystemException('system_schema_write_failed'); }
    }
}
