<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use DateTimeImmutable;
use KK\Korsac\System\SystemException;

/** Only the parent data needed for physical component lifecycle, without owner/Sale/Passport. */
final readonly class ComponentSystemState
{
    public function __construct(public int $id,public string $lifecycleState,public DateTimeImmutable $createdAt)
    {
        self::assertId($id);
        if (!in_array($lifecycleState,['ACTIVE','CANCELLED'],true)) throw new SystemException('system_component_state_invalid');
        ComponentTime::assertValid($createdAt);
    }
    public static function assertId(int $id): void
    {
        if ($id<1 || $id>2147483647) throw new SystemException('system_component_invalid');
    }
}
