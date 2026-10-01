<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

interface OptionViewProviderInterface
{
    public function get(string $group, string $xmlId): OptionView;
}
