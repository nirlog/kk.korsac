<?php

declare(strict_types=1);

namespace Bitrix\Main\Entity;

/** Independent write-entry spies; bulk paths must not delegate through add/update. */
class DataManager
{
    public static array $updates = [];
    public static array $writes = [];

    public static function add(array $data)
    {
        self::$writes[] = __FUNCTION__;
        return ['delegated'=>true];
    }

    public static function addMulti($rows, $ignoreEvents = false)
    {
        self::$writes[] = __FUNCTION__;
        return ['delegated'=>true];
    }

    public static function update($primary, array $data)
    {
        self::$writes[] = __FUNCTION__;
        self::$updates[] = ['primary'=>$primary, 'data'=>$data];
        return ['delegated'=>true];
    }

    public static function updateMulti($primaries, $data, $ignoreEvents = false)
    {
        self::$writes[] = __FUNCTION__;
        return ['delegated'=>true];
    }

    public static function delete($primary)
    {
        self::$writes[] = __FUNCTION__;
        return ['delegated'=>true];
    }
}
