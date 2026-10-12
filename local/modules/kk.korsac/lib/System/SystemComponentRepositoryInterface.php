<?php

declare(strict_types=1);

namespace KK\Korsac\System;
interface SystemComponentRepositoryInterface
{
    public function create(SystemComponent $component): SystemComponent;
    /** @return list<SystemComponent> Includes history unless activeOnly is explicitly requested. */
    public function findForSystem(int $systemId, bool $activeOnly = false): array;
}
