<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use JsonSerializable;

final class ConfigurationPriceResult implements JsonSerializable
{
    public function __construct(
        private readonly int $basePriceMinor,
        private readonly array $groups,
        private readonly int $configurationDeltaMinor,
        private readonly int $finalPriceMinor,
    ) {}

    public function toArray(): array
    {
        return [
            'basePriceMinor' => $this->basePriceMinor,
            'groups' => $this->groups,
            'configurationDeltaMinor' => $this->configurationDeltaMinor,
            'finalPriceMinor' => $this->finalPriceMinor,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
