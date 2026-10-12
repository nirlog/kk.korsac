<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final readonly class OrderSynchronizationResult
{
    public function __construct(public int $orderId,public int $eligibleLineCount,public int $physicalUnitCount,public int $existingSystemCount,public int $createdSystemCount,public array $systemIds) {}
    public function toArray(): array { return get_object_vars($this); }
}
