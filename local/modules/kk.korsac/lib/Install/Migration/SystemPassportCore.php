<?php

declare(strict_types=1);

namespace KK\Korsac\Install\Migration;
use KK\Korsac\Health\PassportSchemaSelfCheck;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\PassportSchemaDefinition;
use KK\Korsac\Install\SystemTableGatewayInterface;
use KK\Korsac\System\SystemException;
final class SystemPassportCore implements MigrationInterface
{
    public const ID = '2026_10_13_008_system_passport_core';
    public function __construct(private readonly SystemTableGatewayInterface $gateway) {}
    public function id(): string { return self::ID; }
    public function up(): void
    {
        // Inspect every existing table before any DDL; never repair incompatible business data silently.
        foreach (PassportSchemaDefinition::tables() as $table=>$definition) {
            $actual = $this->gateway->describe($table);
            if ($actual === null) continue;
            foreach (PassportSchemaSelfCheck::compare($table, $actual) as $error) {
                if ($error['code'] !== 'passport_index_missing' || $error['index'] === 'PRIMARY') throw new SystemException('passport_schema_incompatible', $error);
            }
        }
        foreach (PassportSchemaDefinition::tables() as $table=>$definition) {
            if ($this->gateway->describe($table) === null) $this->gateway->createTable($table);
            $actual = $this->gateway->describe($table);
            foreach ($definition['indexes'] as $name=>$index) {
                if (!isset($actual['indexes'][$name])) $this->gateway->createIndex($table, $name, $index);
            }
        }
        $result = (new PassportSchemaSelfCheck($this->gateway))->run();
        if (!$result['ok']) throw new SystemException('passport_schema_incompatible', $result['errors'][0]);
    }
}
