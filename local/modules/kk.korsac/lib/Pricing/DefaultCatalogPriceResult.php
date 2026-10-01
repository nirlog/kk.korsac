<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use JsonSerializable;

final readonly class DefaultCatalogPriceResult implements JsonSerializable
{
    public function __construct(
        private array $groups,
        private int $componentsRetailMinor,
        private int $systemFixedAdjustmentMinor,
        private int $totalMinor,
    ) {}

    public function toArray(): array
    {
        return ['groups' => $this->groups, 'componentsRetailMinor' => $this->componentsRetailMinor, 'systemFixedAdjustmentMinor' => $this->systemFixedAdjustmentMinor, 'totalMinor' => $this->totalMinor];
    }

    public function jsonSerialize(): array { return $this->toArray(); }
}
