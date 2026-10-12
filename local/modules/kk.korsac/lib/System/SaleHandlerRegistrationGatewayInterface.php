<?php

declare(strict_types=1);

namespace KK\Korsac\System;
interface SaleHandlerRegistrationGatewayInterface
{
    public function count(): int;
    public function register(): void;
    public function unregister(): void;
}
