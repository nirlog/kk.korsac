<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use DateTimeImmutable;
/** Internal record only. It must never be serialized as a public or ordinary CLI view. */
final readonly class PassportRecord
{
    public function __construct(
        public int $systemInternalId, public string $publicId, public string $secretCodeHash,
        public string $state, public ?DateTimeImmutable $transferredAt, public ?DateTimeImmutable $warrantyStartedAt,
        public DateTimeImmutable $preparedAt, public DateTimeImmutable $updatedAt,
    ) {}
    public function assertValid(): void
    {
        if ($this->systemInternalId<1 || $this->systemInternalId>2147483647 || !PassportCredentialShape::token($this->publicId) || !preg_match('/\A[a-f0-9]{64}\z/D',$this->secretCodeHash)) throw new SystemException('system_passport_state_invalid');
        if ($this->state==='PREPARED') {
            if ($this->transferredAt!==null || $this->warrantyStartedAt!==null) throw new SystemException('system_passport_state_invalid');
        } elseif ($this->state==='ACTIVE') {
            if ($this->transferredAt===null || $this->warrantyStartedAt===null || $this->transferredAt!=$this->warrantyStartedAt) throw new SystemException('system_passport_state_invalid');
        } else throw new SystemException('system_passport_state_invalid');
    }
}
