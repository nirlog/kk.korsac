<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

final readonly class ComponentInstallResult
{
    public function __construct(public ComponentCurrentView $component,public bool $created) {}
}
