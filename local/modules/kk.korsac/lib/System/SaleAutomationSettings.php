<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final readonly class SaleAutomationSettings
{
    public function __construct(public bool $enabled, public string $triggerStatusId) {}
}
