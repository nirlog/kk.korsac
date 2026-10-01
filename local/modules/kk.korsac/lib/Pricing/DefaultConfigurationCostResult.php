<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use JsonSerializable;

final class DefaultConfigurationCostResult implements JsonSerializable
{
    public function __construct(
        private readonly array $groups,
        private readonly int $totalMinor,
    ) {}

    public function toArray(): array
    {
        return ['groups' => $this->groups, 'totalMinor' => $this->totalMinor];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
