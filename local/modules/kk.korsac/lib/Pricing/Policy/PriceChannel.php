<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing\Policy;

use InvalidArgumentException;

final class PriceChannel
{
    public const RETAIL = 'RETAIL';
    public const BUSINESS = 'BUSINESS';

    public static function assert(string $channel): string
    {
        if (!in_array($channel, [self::RETAIL, self::BUSINESS], true)) {
            throw new InvalidArgumentException("Unknown KORSAC price channel: {$channel}");
        }
        return $channel;
    }
}
