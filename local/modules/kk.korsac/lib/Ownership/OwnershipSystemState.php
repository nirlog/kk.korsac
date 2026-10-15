<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use DateTimeImmutable;
use KK\Korsac\System\SystemException;

final readonly class OwnershipSystemState
{
    public function __construct(public int $systemInternalId,public string $systemId,public ?int $userId,public string $lifecycleState,public DateTimeImmutable $createdAt,public DateTimeImmutable $updatedAt) {}
    public function assertValid(): void
    {
        if (!OwnershipIdentity::valid($this->systemInternalId) || ($this->userId!==null && !OwnershipIdentity::valid($this->userId)) || !preg_match('/\AKS-[0-9]{2}(?:0[1-9]|1[0-2])-[0-9]{4}\z/D',$this->systemId) || !in_array($this->lifecycleState,['ACTIVE','CANCELLED'],true)) throw new SystemException('system_ownership_state_invalid');
    }
}
