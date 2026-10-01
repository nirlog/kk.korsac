<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use InvalidArgumentException;

final class PriceChannel
{
    public const RETAIL = 'RETAIL';
    public const BUSINESS = 'BUSINESS';

    public static function normalize(string $channel): string
    {
        $channel = strtoupper(trim($channel));
        if (!in_array($channel, [self::RETAIL, self::BUSINESS], true)) {
            throw new InvalidArgumentException('Unknown price channel');
        }
        return $channel;
    }
}
