<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

final readonly class OptionView
{
    public function __construct(
        public string $xmlId,
        public string $name,
        public ?string $description,
    ) {}
}
