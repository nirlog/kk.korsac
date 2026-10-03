<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use InvalidArgumentException;
use RuntimeException;

final class CatalogPropertyInstaller
{
    public function __construct(private readonly CatalogPropertyGatewayInterface $gateway) {}

    public function install(int $iblockId): void
    {
        $this->installWithResult($iblockId);
    }

    public function installWithResult(int $iblockId): array
    {
        if ($iblockId <= 0 || !$this->gateway->iblockExists($iblockId)) {
            throw new InvalidArgumentException("Iblock {$iblockId} does not exist");
        }
        $configuration = $this->ensure($iblockId, CatalogPropertySchema::properties(), true);
        $presentation = $this->ensure($iblockId, ProductPresentationSchema::properties(), false);
        return ['created'=>$configuration['created'] + $presentation['created'], 'existing'=>$configuration['existing'] + $presentation['existing'], 'configuration'=>$configuration, 'presentation'=>$presentation];
    }

    private function ensure(int $iblockId, array $properties, bool $directory): array
    {
        $missing = []; $existing = 0;
        foreach ($properties as $code => $definition) {
            $actual = $this->gateway->find($iblockId, $code);
            if ($actual === null) {
                $missing[] = $definition;
                continue;
            }
            $errors = $this->compatibilityErrors($actual, $definition, $directory);
            if (!$directory && $errors === []) { $errors = $this->enumErrors((int)$actual['ID'], $definition['VALUES']); }
            if ($errors !== []) {
                throw new RuntimeException("KORSAC property {$code} is incompatible: " . implode(', ', $errors));
            }
            ++$existing;
        }
        foreach ($missing as $definition) {
            $this->gateway->create($iblockId, $definition);
        }
        return ['created' => count($missing), 'existing' => $existing];
    }

    private function compatibilityErrors(array $actual, array $expected, bool $directory): array
    {
        $errors = [];
        $fields = ['CODE', 'PROPERTY_TYPE', 'MULTIPLE', 'IS_REQUIRED'];
        if ($directory) { $fields[] = 'USER_TYPE'; }
        foreach ($fields as $field) {
            if (($actual[$field] ?? null) !== $expected[$field]) {
                $errors[] = "expected {$field}={$expected[$field]}, actual {$field}=" . ($actual[$field] ?? 'NULL');
            }
        }
        if ($directory) {
            $expectedTable = $expected['USER_TYPE_SETTINGS']['TABLE_NAME'];
            $actualTable = $actual['USER_TYPE_SETTINGS']['TABLE_NAME'] ?? null;
            if ($actualTable !== $expectedTable) { $errors[] = "expected TABLE_NAME={$expectedTable}, actual TABLE_NAME=" . ($actualTable ?? 'NULL'); }
        }
        return $errors;
    }

    private function enumErrors(int $propertyId, array $expected): array
    {
        $normalize = static fn(array $rows): array => array_map(static fn(array $row): array => ['XML_ID'=>$row['XML_ID'], 'VALUE'=>$row['VALUE'], 'DEF'=>$row['DEF']], $rows);
        return $normalize($this->gateway->enums($propertyId)) === $normalize($expected) ? [] : ['enum definitions differ'];
    }
}
