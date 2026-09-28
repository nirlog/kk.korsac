<?php

declare(strict_types=1);

namespace KK\Korsac\Health;

use DateTimeImmutable;
use DateTimeZone;
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
                if (($actual['USER_TYPE_ID'] ?? null) !== $expected['type']
                    || ($actual['MULTIPLE'] ?? 'N') !== ($expected['multiple'] ? 'Y' : 'N')
                    || ($actual['MANDATORY'] ?? 'N') !== ($expected['required'] ? 'Y' : 'N')) {
                    $errors[] = ['code' => 'field_mismatch', 'entity' => $entity['name'], 'field' => $name];
                }
            }
            $indexes = $this->gateway->getIndexes($entity['table']);
            foreach ($entity['indexes'] as $name => $expected) {
                if (!isset($indexes[$name])) {
                    $errors[] = ['code' => 'missing_index', 'entity' => $entity['name'], 'index' => $name];
                } elseif ($indexes[$name] !== $expected) {
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
        $codes = [];
        foreach ($rowsByEntity as $entity => $rows) {
            $seen = [];
            foreach ($rows as $row) {
                $xmlId = (string)($row['UF_XML_ID'] ?? '');
                if ($xmlId !== '' && isset($seen[$xmlId])) {
                    $errors[] = ['code' => 'duplicate_xml_id', 'entity' => $entity, 'xmlId' => $xmlId];
                }
                $seen[$xmlId] = true;
                if (in_array($entity, SchemaDefinition::COMPONENT_TYPES, true)
                    && isset($row['UF_PRICE']) && (float)$row['UF_PRICE'] < 0) {
                    $errors[] = ['code' => 'negative_price', 'entity' => $entity, 'xmlId' => $xmlId];
                }
            }
            $codes[$entity] = $seen;
        }
        foreach ($rowsByEntity['KorsacPhysicalSku'] ?? [] as $row) {
            $type = (string)($row['UF_COMPONENT_TYPE'] ?? '');
            $block = SchemaDefinition::COMPONENT_TYPES[$type] ?? null;
            if ($block === null || !isset($codes[$block][(string)($row['UF_CLASS_XML_ID'] ?? '')])) {
                $errors[] = ['code' => 'broken_class_reference', 'entity' => 'KorsacPhysicalSku', 'xmlId' => $row['UF_XML_ID'] ?? null];
            }
        }
        $skuCodes = $codes['KorsacPhysicalSku'] ?? [];
        foreach ($rowsByEntity['KorsacSupplierOffer'] ?? [] as $row) {
            if (!isset($skuCodes[(string)($row['UF_PHYSICAL_SKU'] ?? '')])) {
                $errors[] = ['code' => 'broken_sku_reference', 'entity' => 'KorsacSupplierOffer', 'xmlId' => $row['UF_XML_ID'] ?? null];
            }
        }
        foreach ($rowsByEntity['KorsacValidatedBuild'] ?? [] as $row) {
            foreach (['UF_CASE_SKU','UF_MB_SKU','UF_GPU_SKU','UF_PSU_SKU','UF_COOLER_SKU'] as $field) {
                $reference = (string)($row[$field] ?? '');
                if ($reference !== '' && !isset($skuCodes[$reference])) {
                    $errors[] = ['code' => 'broken_build_reference', 'entity' => 'KorsacValidatedBuild', 'xmlId' => $row['UF_XML_ID'] ?? null, 'field' => $field];
                }
            }
            $json = (string)($row['UF_COMPONENTS_JSON'] ?? '');
            if ($json !== '') {
                $decoded = json_decode($json, true);
                if (!is_array($decoded)) {
                    $errors[] = ['code' => 'invalid_components_json', 'entity' => 'KorsacValidatedBuild', 'xmlId' => $row['UF_XML_ID'] ?? null];
                } else {
                    foreach (self::skuReferences($decoded) as $reference) {
                        if (!isset($skuCodes[$reference])) {
                            $errors[] = ['code' => 'broken_build_json_reference', 'entity' => 'KorsacValidatedBuild', 'xmlId' => $row['UF_XML_ID'] ?? null, 'reference' => $reference];
                        }
                    }
                }
            }
        }
        return $errors;
    }

    private static function skuReferences(array $value): array
    {
        $references = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $references = array_merge($references, self::skuReferences($item));
            } elseif (is_string($item) && preg_match('/sku/i', (string)$key) && $item !== '') {
                $references[] = $item;
            }
        }
        return $references;
    }
}
