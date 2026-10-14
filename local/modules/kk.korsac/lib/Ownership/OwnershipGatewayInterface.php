<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use Closure;
use DateTimeImmutable;
use KK\Korsac\System\PassportRecord;

interface OwnershipGatewayInterface
{
    public function transaction(Closure $operation): mixed;
    /** Identification only, not a detached credential authorization. */
    public function identifySystem(string $publicId): ?int;
    public function lockSystem(int $systemInternalId): ?OwnershipSystemState;
    public function lockPassport(int $systemInternalId): ?PassportRecord;
    public function latestHistory(int $systemInternalId): ?OwnershipHistoryRecord;
    /** Fixed conditional first-claim write under the locked parent; no reassignment. */
    public function claimUnownedSystem(int $systemInternalId,int $userId,DateTimeImmutable $at): void;
    public function appendClaim(OwnershipHistoryRecord $record): void;
    /** @return list<OwnershipHistoryRecord> */
    public function historyForSystem(int $systemInternalId): array;
}
