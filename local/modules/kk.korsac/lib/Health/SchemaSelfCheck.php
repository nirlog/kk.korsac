<?php

declare(strict_types=1);

namespace KK\Korsac\Health;

use DateTimeImmutable;
use DateTimeZone;
use KK\Korsac\Install\SchemaComparator;
use KK\Korsac\Install\SchemaDefinition;
use KK\Korsac\Install\SchemaGatewayInterface;

final class SchemaSelfCheck
{
    public function __construct(private readonly SchemaGatewayInterface $gateway) {}

    public function run(): array
    {
        $errors = [];
        $warnings = [];
        foreach (SchemaDefinition::entities() as $entity) {
            $block = $this->gateway->getBlock($entity['name']);
            if ($block === null) {
                $errors[] = ['code' => 'missing_block', 'entity' => $entity['name']];
                continue;
            }
            if (($block['TABLE_NAME'] ?? null) !== $entity['table']) {
                $errors[] = ['code' => 'table_mismatch', 'entity' => $entity['name'], 'expected' => $entity['table']];
            }
            $fields = $this->gateway->getFields((int)$block['ID']);
            foreach ($entity['fields'] as $name => $expected) {
                if (!isset($fields[$name])) {
                    $errors[] = ['code' => 'missing_field', 'entity' => $entity['name'], 'field' => $name];
                    continue;
                }
                $actual = $fields[$name];
                if (!SchemaComparator::fieldIsCompatible($actual, $expected)) {
                    $errors[] = ['code' => 'field_mismatch', 'entity' => $entity['name'], 'field' => $name];
                }
            }
            $indexes = $this->gateway->getIndexes($entity['table']);
            foreach ($entity['indexes'] as $name => $expected) {
                if (!isset($indexes[$name])) {
                    $errors[] = ['code' => 'missing_index', 'entity' => $entity['name'], 'index' => $name];
                } elseif (!SchemaComparator::indexIsCompatible($indexes[$name], $expected)) {
                    $errors[] = ['code' => 'index_mismatch', 'entity' => $entity['name'], 'index' => $name];
                }
            }
        }
        $rows = [];
        foreach (array_keys(SchemaDefinition::entities()) as $name) {
            if ($this->gateway->getBlock($name) !== null) {
                $rows[$name] = $this->gateway->rows($name);
            }
        }
        $errors = array_merge($errors, self::analyzeData($rows));
        return [
            'ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings,
            'checkedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /** @param array<string,list<array>> $rowsByEntity */
    public static function analyzeData(array $rowsByEntity): array
    {
        $errors = [];
        foreach ($rowsByEntity as $entity => $rows) {
            $seen = [];
            foreach ($rows as $row) {
                $xmlId = (string)($row['UF_XML_ID'] ?? '');
                if ($xmlId === '') {
                    $errors[] = ['code' => 'empty_xml_id', 'entity' => $entity];
                } elseif (isset($seen[$xmlId])) {
                    $errors[] = ['code' => 'duplicate_xml_id', 'entity' => $entity, 'xmlId' => $xmlId];
                }
                $seen[$xmlId] = true;
                if (isset($row['UF_PRICE']) && (float)$row['UF_PRICE'] < 0) {
                    $errors[] = ['code' => 'negative_price', 'entity' => $entity, 'xmlId' => $xmlId];
                }
            }
        }
        return $errors;
    }
}
