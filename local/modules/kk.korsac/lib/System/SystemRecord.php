<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use DateTimeImmutable;
/** Internal domain record, not a public Passport DTO. */
final readonly class SystemRecord
{
    public function __construct(
        public int $id, public string $systemId, public SystemCreationRequest $source,
        public ?int $userId, public string $lifecycleState,
        public DateTimeImmutable $createdAt, public DateTimeImmutable $updatedAt,
    ) {}
}
