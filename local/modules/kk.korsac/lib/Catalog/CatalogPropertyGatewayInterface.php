<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

interface CatalogPropertyGatewayInterface
{
    public function iblockExists(int $iblockId): bool;
    public function find(int $iblockId, string $code): ?array;
    public function create(int $iblockId, array $property): int;
    /** @return list<string> */
    public function values(int $iblockId, int $productId, string $code): array;
    public function productExists(int $iblockId, int $productId): bool;
}
