<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use DateTimeImmutable;
final readonly class ProductionStatusHistory
{
    public function __construct(public int $systemInternalId, public int $revision, public ?string $fromStatusCode, public string $toStatusCode, public string $sourceCode, public ?int $actorUserId, public DateTimeImmutable $createdAt) {}
}
