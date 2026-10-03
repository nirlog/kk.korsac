<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

final readonly class ProductPresentation
{
    public function __construct(public array $modes, public array $warnings = []) {}
    public function mode(string $group): string { return $this->modes[$group] ?? ProductPresentationSchema::DEFAULT_MODE; }
}
