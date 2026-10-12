<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use DateTimeImmutable;
final readonly class ProductionStatusState
{
    public function __construct(public int $systemInternalId, public string $statusCode, public int $revision, public DateTimeImmutable $updatedAt) {}
}
