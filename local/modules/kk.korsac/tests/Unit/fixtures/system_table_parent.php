<?php

declare(strict_types=1);

namespace Bitrix\Main\Entity;

/** Single-method parent double: exercises SystemTable's actual guard, not live ORM. */
class DataManager
{
    public static array $updates = [];

    public static function update($primary, array $data)
    {
        self::$updates[] = ['primary'=>$primary, 'data'=>$data];
        return ['delegated'=>true];
    }
}
