<?php

declare(strict_types=1);

namespace KK\Korsac\Install\Migration;
use KK\Korsac\Health\SystemSchemaSelfCheck;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\SystemSchemaDefinition;
use KK\Korsac\Install\SystemTableGatewayInterface;
use KK\Korsac\System\SystemException;
final class SystemCore implements MigrationInterface
{
    public const ID = '2026_10_12_006_system_core';
    public function __construct(private readonly SystemTableGatewayInterface $gateway) {}
    public function id(): string { return self::ID; }
    public function up(): void
    {
        // Inspect every existing table before any DDL; never repair incompatible business data silently.
        foreach (SystemSchemaDefinition::tables() as $table=>$definition) {
            $actual = $this->gateway->describe($table);
            if ($actual === null) continue;
            foreach (SystemSchemaSelfCheck::compare($table, $actual) as $error) {
                if ($error['code'] !== 'system_index_missing' || $error['index'] === 'PRIMARY') throw new SystemException('system_schema_incompatible', $error);
            }
        }
        foreach (SystemSchemaDefinition::tables() as $table=>$definition) {
            if ($this->gateway->describe($table) === null) $this->gateway->createTable($table);
            $actual = $this->gateway->describe($table);
            foreach ($definition['indexes'] as $name=>$index) {
                if (!isset($actual['indexes'][$name])) $this->gateway->createIndex($table, $name, $index);
            }
        }
        $result = (new SystemSchemaSelfCheck($this->gateway))->run();
        if (!$result['ok']) throw new SystemException('system_schema_incompatible', $result['errors'][0]);
    }
}
