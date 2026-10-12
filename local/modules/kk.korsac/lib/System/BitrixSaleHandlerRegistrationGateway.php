<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
final class BitrixSaleHandlerRegistrationGateway implements SaleHandlerRegistrationGatewayInterface
{
    public const HANDLER=SaleOrderSavedHandler::class;
    public function count(): int
    {
        $connection=Application::getConnection(); $class=$connection->getSqlHelper()->forSql(self::HANDLER);
        $r=$connection->query("SELECT COUNT(*) AS C, SUM(CASE WHEN VERSION=2 THEN 1 ELSE 0 END) AS V FROM b_module_to_module WHERE FROM_MODULE_ID='sale' AND MESSAGE_ID='OnSaleOrderSaved' AND TO_MODULE_ID='kk.korsac' AND TO_CLASS='{$class}' AND TO_METHOD='onOrderSaved' AND (TO_PATH='' OR TO_PATH IS NULL) AND (TO_METHOD_ARG='' OR TO_METHOD_ARG IS NULL)")->fetch();
        return (int)$r['V']===0 ? 0 : (int)$r['C'];
    }
    public function register(): void { EventManager::getInstance()->registerEventHandler('sale','OnSaleOrderSaved','kk.korsac',self::HANDLER,'onOrderSaved'); }
    public function unregister(): void { EventManager::getInstance()->unRegisterEventHandler('sale','OnSaleOrderSaved','kk.korsac',self::HANDLER,'onOrderSaved'); }
}
