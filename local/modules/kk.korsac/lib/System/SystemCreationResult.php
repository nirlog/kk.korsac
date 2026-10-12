<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final readonly class SystemCreationResult
{
    public function __construct(public SystemRecord $system,public bool $created) {}
}
