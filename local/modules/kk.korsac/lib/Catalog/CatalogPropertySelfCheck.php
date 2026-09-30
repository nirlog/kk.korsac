<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use InvalidArgumentException;

final class CatalogPropertySelfCheck
{
    public function __construct(private readonly CatalogPropertyGatewayInterface $gateway) {}

    public function run(int $iblockId): array
    {
        if ($iblockId <= 0 || !$this->gateway->iblockExists($iblockId)) {
            throw new InvalidArgumentException("Iblock {$iblockId} does not exist");
        }
        $errors = [];
        foreach (CatalogPropertySchema::properties() as $code => $expected) {
            $actual = $this->gateway->find($iblockId, $code);
            if ($actual === null) {
                $errors[] = ['code' => 'missing_property', 'property' => $code];
                continue;
            }
            foreach ([
                'CODE' => 'property_code_mismatch',
                'PROPERTY_TYPE' => 'property_type_mismatch',
                'USER_TYPE' => 'property_user_type_mismatch',
                'MULTIPLE' => 'property_multiple_mismatch',
                'IS_REQUIRED' => 'property_required_mismatch',
            ] as $field => $errorCode) {
                if (($actual[$field] ?? null) !== $expected[$field]) {
                    $errors[] = ['code' => $errorCode, 'property' => $code, 'expected' => $expected[$field], 'actual' => $actual[$field] ?? null];
                }
            }
            $expectedTable = $expected['USER_TYPE_SETTINGS']['TABLE_NAME'];
            $actualTable = $actual['USER_TYPE_SETTINGS']['TABLE_NAME'] ?? null;
            if ($actualTable !== $expectedTable) {
                $errors[] = ['code' => 'property_directory_mismatch', 'property' => $code, 'expected' => $expectedTable, 'actual' => $actualTable];
            }
        }
        return ['ok' => $errors === [], 'iblockId' => $iblockId, 'properties' => count(CatalogPropertySchema::properties()), 'errors' => $errors, 'warnings' => []];
    }
}
