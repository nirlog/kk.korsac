<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
use Bitrix\Main\Application;
use KK\Korsac\System\SystemException;
use Throwable;
final class BitrixProductionStatusTableGateway implements SystemTableGatewayInterface
{
    private object $connection;
    public function __construct() { $this->connection = Application::getConnection(); }
    public function describe(string $table): ?array
    {
        if (!isset(ProductionStatusSchemaDefinition::tables()[$table])) throw new SystemException('production_schema_invalid');
        return (new SqlTableInspector())->describe($table);
    }
    public function createTable(string $table): void
    {
        try { $this->connection->queryExecute(ProductionStatusSchemaDefinition::createSql($table)); }
        catch (Throwable) { throw new SystemException('production_schema_write_failed'); }
    }
    public function createIndex(string $table, string $name, array $index): void
    {
        if ((ProductionStatusSchemaDefinition::tables()[$table]['indexes'][$name] ?? null) !== $index || $name === 'PRIMARY') throw new SystemException('production_schema_invalid');
        $columns = implode(',', array_map(static fn(string $column): string => "`{$column}`", $index['columns']));
        $kind = $index['unique'] ? 'UNIQUE INDEX' : 'INDEX';
        try { $this->connection->queryExecute("CREATE {$kind} `{$name}` ON `{$table}` ({$columns})"); }
        catch (Throwable) { throw new SystemException('production_schema_write_failed'); }
    }
}
