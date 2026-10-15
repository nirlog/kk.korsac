<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use RuntimeException;

/** Fixed categories are for data-health counts only; service errors retain just their code. */
final class ComponentStateInvalid extends RuntimeException
{
    public function __construct(public readonly string $category) { parent::__construct('system_component_state_invalid'); }
}
