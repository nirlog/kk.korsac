<?php

declare(strict_types=1);

namespace KK\Korsac\Admin;

interface PricingAdminGatewayInterface
{
    public function catalogExists(int $iblockId): bool;
    public function priceTypeExists(int $priceTypeId): bool;
    public function readOption(string $key): ?string;
    public function writeOption(string $key, string $value): void;
    public function removeOption(string $key): void;
    public function catalogs(): array;
    public function priceTypes(): array;
}
