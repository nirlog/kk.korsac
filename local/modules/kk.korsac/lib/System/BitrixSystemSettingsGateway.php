<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\StatusTable;
final class BitrixSystemSettingsGateway implements SystemSettingsGatewayInterface
{
    public function get(string $name, string $default = ''): string { return Option::get('kk.korsac',$name,$default); }
    public function set(string $name, string $value): void { Option::set('kk.korsac',$name,$value); }
    public function isOrderStatus(string $statusId): bool
    {
        if (!Loader::includeModule('sale')) throw new SystemException('system_sale_required');
        return (bool)StatusTable::getList(['filter'=>['=ID'=>$statusId,'=TYPE'=>'O'],'select'=>['ID'],'limit'=>1])->fetch();
    }
}
