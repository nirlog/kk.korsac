<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
use Bitrix\Main\Application;
use KK\Korsac\System\SystemException;
use Throwable;
final class BitrixOwnershipTableGateway implements OwnershipTableGatewayInterface
{
    private object $connection;
    public function __construct() { $this->connection = Application::getConnection(); }
    public function describe(string $table): ?array
    {
        if (!isset(OwnershipSchemaDefinition::tables()[$table])) throw new SystemException('ownership_schema_invalid');
        try { return (new SqlTableInspector())->describe($table); }
        catch (Throwable) { throw new SystemException('ownership_schema_read_failed'); }
    }
    public function legacyOwnerCount(): int
    {
        try {
            $table=SystemSchemaDefinition::SYSTEM;
            $row=$this->connection->query("SELECT COUNT(*) AS CNT FROM `{$table}` WHERE `USER_ID` IS NOT NULL")->fetch();
            if (!$row || !isset($row['CNT']) || !ctype_digit((string)$row['CNT'])) throw new SystemException('ownership_schema_read_failed');
            return (int)$row['CNT'];
        } catch (Throwable) { throw new SystemException('ownership_schema_read_failed'); }
    }
    public function createTable(string $table): void
    {
        try { $this->connection->queryExecute(OwnershipSchemaDefinition::createSql($table)); }
        catch (Throwable) { throw new SystemException('ownership_schema_write_failed'); }
    }
    public function createIndex(string $table, string $name, array $index): void
    {
        if ((OwnershipSchemaDefinition::tables()[$table]['indexes'][$name] ?? null) !== $index || $name === 'PRIMARY') throw new SystemException('ownership_schema_invalid');
        $columns = implode(',', array_map(static fn(string $column): string => "`{$column}`", $index['columns']));
        $kind = $index['unique'] ? 'UNIQUE INDEX' : 'INDEX';
        try { $this->connection->queryExecute("CREATE {$kind} `{$name}` ON `{$table}` ({$columns})"); }
        catch (Throwable) { throw new SystemException('ownership_schema_write_failed'); }
    }
}
