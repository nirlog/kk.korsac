<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use KK\Korsac\Exception\SchemaMismatchException;

final class SchemaInstaller
{
    public function __construct(private readonly SchemaGatewayInterface $gateway)
    {
    }

    public function install(): void
    {
        foreach (SchemaDefinition::entities() as $entity) {
            $block = $this->ensureBlock($entity);
            $this->ensureFields((int)$block['ID'], $entity);
            $this->ensureIndexes($entity);
        }
    }

    private function ensureBlock(array $entity): array
    {
        $byName = $this->gateway->getBlock($entity['name']);
        $byTable = $this->gateway->getBlockByTable($entity['table']);
        if ($byName === null && $byTable === null) {
            return $this->gateway->createBlock($entity['name'], $entity['table']);
        }
        if ($byName === null || $byTable === null || (int)$byName['ID'] !== (int)$byTable['ID']
            || $byName['TABLE_NAME'] !== $entity['table'] || $byTable['NAME'] !== $entity['name']) {
            throw new SchemaMismatchException("HL block {$entity['name']} conflicts with table {$entity['table']}");
        }
        return $byName;
    }

    private function ensureFields(int $blockId, array $entity): void
    {
        $existing = $this->gateway->getFields($blockId);
        foreach ($entity['fields'] as $name => $expected) {
            if (!isset($existing[$name])) {
                $this->gateway->createField($blockId, $expected);
                continue;
            }
            $actual = $existing[$name];
            $compatible = ($actual['USER_TYPE_ID'] ?? null) === $expected['type']
                && ($actual['MULTIPLE'] ?? 'N') === ($expected['multiple'] ? 'Y' : 'N')
                && ($actual['MANDATORY'] ?? 'N') === ($expected['required'] ? 'Y' : 'N');
            if (!$compatible) {
                throw new SchemaMismatchException("Incompatible field {$entity['name']}.{$name}");
            }
        }
    }

    private function ensureIndexes(array $entity): void
    {
        $existing = $this->gateway->getIndexes($entity['table']);
        foreach ($entity['indexes'] as $name => $expected) {
            if (isset($existing[$name])) {
                if ($existing[$name] !== $expected) {
                    throw new SchemaMismatchException("Incompatible index {$entity['table']}.{$name}");
                }
                continue;
            }
            if ($expected['unique']) {
                foreach ($expected['columns'] as $column) {
                    $duplicates = $this->gateway->findDuplicateValues($entity['table'], $column);
                    if ($duplicates !== []) {
                        throw new SchemaMismatchException("Cannot create {$name}: duplicate {$column} values exist");
                    }
                }
            }
            $this->gateway->createIndex($entity['table'], $name, $expected['columns'], $expected['unique']);
        }
    }
}
