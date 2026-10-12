<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final readonly class OrderProjection
{
    /** @param list<OrderBasketLine> $lines */
    public function __construct(public int $orderId,public string $siteId,public string $statusId,public array $lines) {}
}
