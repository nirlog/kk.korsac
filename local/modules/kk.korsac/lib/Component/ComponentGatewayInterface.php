<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use Closure;
use DateTimeImmutable;
use KK\Korsac\System\SystemComponent;

interface ComponentGatewayInterface
{
    public function transaction(Closure $operation): mixed;
    public function lockSystem(int $systemInternalId): ?ComponentSystemState;
    /** Locks the System's full timeline (including other slots for serial validation).
     * Null slot is read-only; a concrete slot fixes the sole mutation scope.
     * @return list<SystemComponent> */
    public function lockComponents(int $systemInternalId,?string $slotKey=null): array;
    public function closeComponent(int $systemInternalId,int $expectedComponentId,string $slotKey,DateTimeImmutable $at): void;
    public function insertComponent(int $systemInternalId,ComponentSpec $spec,DateTimeImmutable $at): SystemComponent;
}
