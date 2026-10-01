<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

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
        if (!isset(self::MODES[$group])) {
            throw new ConfigurationPricingException(['code' => 'unknown_pricing_group', 'group' => $group]);
        }
        return self::MODES[$group];
    }
}
