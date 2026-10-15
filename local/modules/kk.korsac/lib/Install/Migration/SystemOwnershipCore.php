<?php

declare(strict_types=1);

namespace KK\Korsac\Install\Migration;
use KK\Korsac\Health\OwnershipSchemaSelfCheck;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\OwnershipSchemaDefinition;
use KK\Korsac\Install\OwnershipTableGatewayInterface;
use KK\Korsac\System\SystemException;
use KK\Korsac\Install\OwnershipLegacyAssignmentsException;
final class SystemOwnershipCore implements MigrationInterface
{
    public const ID = '2026_10_14_009_system_ownership_core';
    public function __construct(private readonly OwnershipTableGatewayInterface $gateway) {}
    public function id(): string { return self::ID; }
    public function up(): void
    {
        $count=$this->gateway->legacyOwnerCount();
        if ($count>0) throw new OwnershipLegacyAssignmentsException($count);
        // Inspect every existing table before any DDL; never repair incompatible business data silently.
        foreach (OwnershipSchemaDefinition::tables() as $table=>$definition) {
            $actual = $this->gateway->describe($table);
            if ($actual === null) continue;
            foreach (OwnershipSchemaSelfCheck::compare($table, $actual) as $error) {
                if ($error['code'] !== 'ownership_index_missing' || $error['index'] === 'PRIMARY') throw new SystemException('ownership_schema_incompatible', $error);
            }
        }
        foreach (OwnershipSchemaDefinition::tables() as $table=>$definition) {
            if ($this->gateway->describe($table) === null) $this->gateway->createTable($table);
            $actual = $this->gateway->describe($table);
            foreach ($definition['indexes'] as $name=>$index) {
                if (!isset($actual['indexes'][$name])) $this->gateway->createIndex($table, $name, $index);
            }
        }
        $result = (new OwnershipSchemaSelfCheck($this->gateway))->run();
        if (!$result['ok']) throw new SystemException('ownership_schema_incompatible', $result['errors'][0]);
    }
}
