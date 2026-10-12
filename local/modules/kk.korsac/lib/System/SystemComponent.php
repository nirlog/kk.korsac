<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use DateTimeImmutable;
/** Physical installation history; serialNumber is private internal/service data. */
final readonly class SystemComponent
{
    public function __construct(
        public int $id, public int $systemInternalId, public string $typeCode, public string $slotKey,
        public string $slotLabel, public string $name, public ?string $serialNumber,
        public DateTimeImmutable $installedAt, public ?DateTimeImmutable $removedAt,
        public DateTimeImmutable $createdAt, public DateTimeImmutable $updatedAt,
    ) {
        if ($id < 0 || $id > 2147483647 || $systemInternalId < 1 || $systemInternalId > 2147483647) throw new SystemException('system_component_invalid');
        foreach ([[$typeCode,32], [$slotKey,64]] as [$value,$limit]) {
            if (strlen((string)$value) > $limit || !preg_match('/\A[A-Z][A-Z0-9_]*\z/D', (string)$value)) throw new SystemException('system_component_invalid');
        }
        foreach ([$slotLabel, $name, $serialNumber] as $value) {
            if ($value !== null && (!mb_check_encoding($value, 'UTF-8') || trim($value) === '' || mb_strlen($value, 'UTF-8') > 255 || preg_match('/[\x00-\x1f\x7f]/u', $value))) throw new SystemException('system_component_invalid');
        }
        if ($removedAt !== null && $removedAt < $installedAt) throw new SystemException('system_component_invalid');
    }
}
