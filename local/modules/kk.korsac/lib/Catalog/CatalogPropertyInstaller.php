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
        $missing = [];
        $existing = 0;
        foreach (CatalogPropertySchema::properties() as $code => $definition) {
            $actual = $this->gateway->find($iblockId, $code);
            if ($actual === null) {
                $missing[] = $definition;
                continue;
            }
            $errors = $this->compatibilityErrors($actual, $definition);
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

    private function compatibilityErrors(array $actual, array $expected): array
    {
        $errors = [];
        foreach (['CODE', 'PROPERTY_TYPE', 'USER_TYPE', 'MULTIPLE'] as $field) {
            if (($actual[$field] ?? null) !== $expected[$field]) {
                $errors[] = "expected {$field}={$expected[$field]}, actual {$field}=" . ($actual[$field] ?? 'NULL');
            }
        }
        $expectedTable = $expected['USER_TYPE_SETTINGS']['TABLE_NAME'];
        $actualTable = $actual['USER_TYPE_SETTINGS']['TABLE_NAME'] ?? null;
        if ($actualTable !== $expectedTable) {
            $errors[] = "expected TABLE_NAME={$expectedTable}, actual TABLE_NAME=" . ($actualTable ?? 'NULL');
        }
        return $errors;
    }
}
