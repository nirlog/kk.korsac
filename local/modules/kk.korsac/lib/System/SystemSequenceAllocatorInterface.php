<?php

declare(strict_types=1);

namespace KK\Korsac\System;
interface SystemSequenceAllocatorInterface
{
    /** Durably consume a number in 1..9999; never decrement or reuse. */
    public function allocate(string $period): int;
}
