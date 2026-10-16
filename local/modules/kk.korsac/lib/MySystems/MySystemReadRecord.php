<?php

declare(strict_types=1);

namespace KK\Korsac\MySystems;

use DateTimeImmutable;
use KK\Korsac\Ownership\OwnershipHistoryRecord;
use KK\Korsac\System\PassportRecord;
use KK\Korsac\System\SystemComponent;

/** Private gateway input. Never return/serialize this record to account or CLI callers. */
final readonly class MySystemReadRecord
{
    /** @param list<SystemComponent> $components */
    public function __construct(
        public int $internalId,public ?int $userId,public string $systemId,public string $systemType,
        public string $modelName,public string $lifecycleState,public DateTimeImmutable $createdAt,public DateTimeImmutable $updatedAt,
        #[\SensitiveParameter] public ?PassportRecord $passport,#[\SensitiveParameter] public array $components,public ?OwnershipHistoryRecord $latestOwnership,
    ) {}
}
