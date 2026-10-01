<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

use RuntimeException;

final class ConfiguratorException extends RuntimeException
{
    public function __construct(private readonly array $diagnostic)
    {
        parent::__construct((string)($diagnostic['code'] ?? 'configurator_error'));
    }

    public function diagnostic(): array
    {
        return $this->diagnostic;
    }
}
