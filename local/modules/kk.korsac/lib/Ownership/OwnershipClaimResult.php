<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use DateTimeImmutable;

/** Internal application result, never a public Passport DTO. No credential fields. */
final readonly class OwnershipClaimResult
{
    public function __construct(public int $systemInternalId,public string $systemId,public int $userId,public bool $claimed,public int $revision,public DateTimeImmutable $claimedAt) {}
}
