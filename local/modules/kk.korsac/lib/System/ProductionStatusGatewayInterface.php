<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Closure;
interface ProductionStatusGatewayInterface
{
    public function transaction(Closure $operation): ProductionStatusState;
    /** Lock the parent System anchor first, including when no current status exists yet. */
    public function lockCurrent(int $systemInternalId): ?ProductionStatusState;
    public function writeCurrent(ProductionStatusState $state, bool $insert): void;
    public function appendHistory(ProductionStatusHistory $history): void;
    public function findCurrent(int $systemInternalId): ?ProductionStatusState;
    /** @return list<ProductionStatusHistory> */
    public function history(int $systemInternalId): array;
}
