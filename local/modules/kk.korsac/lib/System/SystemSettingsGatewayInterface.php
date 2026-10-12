<?php

declare(strict_types=1);

namespace KK\Korsac\System;
interface SystemSettingsGatewayInterface
{
    public function get(string $name, string $default = ''): string;
    public function set(string $name, string $value): void;
    public function isOrderStatus(string $statusId): bool;
}
