<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use DateTimeImmutable;
use KK\Korsac\System\SystemException;

final readonly class OwnershipHistoryRecord
{
    public function __construct(public int $id,public int $systemInternalId,public int $revision,public ?int $fromUserId,public ?int $toUserId,public string $eventCode,public string $sourceCode,public ?int $actorUserId,public DateTimeImmutable $createdAt) {}
    public function assertValid(): void
    {
        if ($this->id<0 || $this->id>OwnershipIdentity::MAX || !OwnershipIdentity::valid($this->systemInternalId) || !OwnershipIdentity::valid($this->revision) || $this->fromUserId!==null || $this->toUserId===null || !OwnershipIdentity::valid($this->toUserId) || $this->actorUserId!==$this->toUserId || $this->eventCode!=='CLAIMED' || $this->sourceCode!=='SECRET_CLAIM') throw new SystemException('system_ownership_state_invalid');
    }
    public function toArray(): array
    {
        return ['revision'=>$this->revision,'fromUserId'=>$this->fromUserId,'toUserId'=>$this->toUserId,'eventCode'=>$this->eventCode,'sourceCode'=>$this->sourceCode,'actorUserId'=>$this->actorUserId,'createdAt'=>$this->createdAt->format(DATE_ATOM)];
    }
}
