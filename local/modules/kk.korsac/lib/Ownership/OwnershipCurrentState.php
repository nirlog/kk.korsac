<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use DateTimeImmutable;

final readonly class OwnershipCurrentState
{
    public function __construct(public string $systemId,public ?int $userId,public ?int $revision,public ?DateTimeImmutable $claimedAt) {}
    public function toArray(): array { return ['systemId'=>$this->systemId,'userId'=>$this->userId,'revision'=>$this->revision,'claimedAt'=>$this->claimedAt?->format(DATE_ATOM)]; }
}
