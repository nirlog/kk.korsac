<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use KK\Korsac\Repository\OptionTypeRegistry;

final class CatalogPropertySchema
{
    public const MODE_SINGLE = 'single';
    public const MODE_MULTIPLE = 'multiple';

    private const GROUPS = [
        'CPU' => self::MODE_SINGLE, 'GPU' => self::MODE_SINGLE, 'MB' => self::MODE_SINGLE,
        'RAM' => self::MODE_SINGLE, 'SSD' => self::MODE_SINGLE, 'HDD' => self::MODE_SINGLE,
        'PSU' => self::MODE_SINGLE, 'COOLER' => self::MODE_SINGLE, 'CASE' => self::MODE_SINGLE,
        'OS' => self::MODE_SINGLE, 'SOFTWARE' => self::MODE_MULTIPLE, 'SERVICE' => self::MODE_MULTIPLE,
    ];

    /** @return array<string,array{optionType:string,mode:string}> */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::GROUPS as $group => $mode) {
            $groups[$group] = ['optionType' => $group, 'mode' => $mode];
        }
        return $groups;
    }

    /** @return array<string,array<string,mixed>> */
    public static function properties(): array
    {
        $properties = [];
        foreach (self::groups() as $group => $definition) {
            $roles = $definition['mode'] === self::MODE_SINGLE ? ['DEFAULT', 'OPTIONS'] : ['MULTI_OPTIONS'];
            foreach ($roles as $role) {
                $code = "KK_{$group}_{$role}";
                $properties[$code] = [
                    'CODE' => $code,
                    'NAME' => "KORSAC {$group} {$role}",
                    'PROPERTY_TYPE' => 'S',
                    'USER_TYPE' => 'directory',
                    'MULTIPLE' => $role === 'DEFAULT' ? 'N' : 'Y',
                    'IS_REQUIRED' => 'N',
                    'SORT' => 500,
                    'USER_TYPE_SETTINGS' => ['TABLE_NAME' => OptionTypeRegistry::tableName($definition['optionType'])],
                ];
            }
        }
        return $properties;
    }
}
