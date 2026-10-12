<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use DateTimeImmutable;
/** Minimal current parent state read under lock; not a second System persistence model. */
final readonly class PassportSystemState
{
    public function __construct(public int $systemInternalId, public string $lifecycleState, public DateTimeImmutable $createdAt) {}
}
