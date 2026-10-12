<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use RuntimeException;
final class SystemException extends RuntimeException
{
    public function __construct(public readonly string $machineCode, private readonly array $context = []) { parent::__construct($machineCode); }
    public function diagnostic(): array { return ['code' => $this->machineCode] + array_intersect_key($this->context, array_flip(['table','field','index'])); }
}
