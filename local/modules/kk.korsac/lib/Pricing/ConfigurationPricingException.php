<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use RuntimeException;

final class ConfigurationPricingException extends RuntimeException
{
    public function __construct(private readonly array $diagnostic)
    {
        parent::__construct(json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'Configuration pricing error');
    }

    public function diagnostic(): array
    {
        return $this->diagnostic;
    }
}
