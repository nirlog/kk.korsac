<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
final class MinorUnitFormatter
{
    public static function decimal(int $minor): string
    {
        if ($minor < 0) throw new CartException(['code'=>'basket_save_failed']);
        return intdiv($minor, 100) . '.' . str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
