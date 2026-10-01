<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use InvalidArgumentException;

final class OptionPricingModeRegistry
{
    public const PROCUREMENT = 'PROCUREMENT';
    public const RETAIL = 'RETAIL';

    private const MODES = [
        'CPU' => self::PROCUREMENT, 'GPU' => self::PROCUREMENT, 'MB' => self::PROCUREMENT,
        'RAM' => self::PROCUREMENT, 'SSD' => self::PROCUREMENT, 'HDD' => self::PROCUREMENT,
        'PSU' => self::PROCUREMENT, 'COOLER' => self::PROCUREMENT, 'CASE' => self::PROCUREMENT,
        'OS' => self::RETAIL, 'SOFTWARE' => self::RETAIL, 'SERVICE' => self::RETAIL,
    ];

    public static function mode(string $group): string
    {
        return self::MODES[$group] ?? throw new InvalidArgumentException("Unknown KORSAC pricing group: {$group}");
    }

    public static function all(): array
    {
        return self::MODES;
    }
}
