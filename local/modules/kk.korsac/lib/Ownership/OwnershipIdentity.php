<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

final class OwnershipIdentity
{
    public const MAX=2147483647;
    public static function valid(int $value): bool { return $value>=1 && $value<=self::MAX; }
}
