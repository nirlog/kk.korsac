<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use RuntimeException;

final class ProductConfigurationException extends RuntimeException
{
    public function __construct(private readonly array $diagnostic)
    {
        parent::__construct(json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'Invalid product configuration');
    }

    public function diagnostic(): array
    {
        return $this->diagnostic;
    }
}
