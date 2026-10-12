<?php

declare(strict_types=1);

namespace KK\Korsac\System;
interface SystemRepositoryInterface
{
    public function create(SystemRecord $system): SystemRecord;
    public function findById(int $id): ?SystemRecord;
    public function findBySystemId(string $systemId): ?SystemRecord;
    public function findByOrderUnit(int $orderId, int $basketId, int $unitIndex, bool $currentRead = false): ?SystemRecord;
}
