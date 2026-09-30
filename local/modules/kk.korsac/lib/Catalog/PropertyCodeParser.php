<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use InvalidArgumentException;
use KK\Korsac\Repository\OptionTypeRegistry;

final class PropertyCodeParser
{
    /** @return array{group:string,role:string,mode:string} */
    public function parse(string $code): array
    {
        if (!str_starts_with($code, 'KK_')) {
            throw new InvalidArgumentException("Invalid KORSAC property code: {$code}");
        }
        foreach ([
            '_MULTI_OPTIONS' => ['MULTI_OPTIONS', 'multiple'],
            '_OPTIONS' => ['OPTIONS', 'single'],
            '_DEFAULT' => ['DEFAULT', 'single'],
        ] as $suffix => [$role, $mode]) {
            if (str_ends_with($code, $suffix)) {
                $group = substr($code, 3, -strlen($suffix));
                if ($group === '' || $group !== strtoupper($group) || !OptionTypeRegistry::has($group)) {
                    throw new InvalidArgumentException("Unknown KORSAC option group in property code: {$code}");
                }
                if (($mode === 'multiple') !== (CatalogPropertySchema::groups()[$group]['mode'] === 'multiple')) {
                    throw new InvalidArgumentException("Property role is not valid for KORSAC group: {$code}");
                }
                return compact('group', 'role', 'mode');
            }
        }
        throw new InvalidArgumentException("Invalid KORSAC property code: {$code}");
    }
}
