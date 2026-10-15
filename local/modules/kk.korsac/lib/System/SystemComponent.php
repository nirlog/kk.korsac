<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use DateTimeImmutable;
use KK\Korsac\Component\ComponentSpec;
use KK\Korsac\Component\ComponentTime;
/** Physical installation history; serialNumber is private internal/service data. */
final readonly class SystemComponent
{
    public function __construct(
        public int $id, public int $systemInternalId, public string $typeCode, public string $slotKey,
        public string $slotLabel, public string $name, #[\SensitiveParameter] public ?string $serialNumber,
        public DateTimeImmutable $installedAt, public ?DateTimeImmutable $removedAt,
        public DateTimeImmutable $createdAt, public DateTimeImmutable $updatedAt,
    ) {
        if ($id < 0 || $id > 2147483647 || $systemInternalId < 1 || $systemInternalId > 2147483647) throw new SystemException('system_component_invalid');
        new ComponentSpec($typeCode,$slotKey,$slotLabel,$name,$serialNumber);
        foreach ([$installedAt,$removedAt,$createdAt,$updatedAt] as $at) if ($at!==null) ComponentTime::assertValid($at);
        if ($removedAt !== null && $removedAt < $installedAt) throw new SystemException('system_component_invalid');
    }
}
