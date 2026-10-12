<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final class BitrixSaleSynchronizationLogger implements SaleSynchronizationLoggerInterface
{
    public function log(int $orderId,string $code): void
    {
        $code=self::safeCode($code); $orderId=$orderId>=1 && $orderId<=2147483647 ? $orderId : 0;
        \CEventLog::Add(['SEVERITY'=>'ERROR','AUDIT_TYPE_ID'=>'KORSAC_SYSTEM_SYNC_FAILED','MODULE_ID'=>'kk.korsac','ITEM_ID'=>(string)$orderId,'DESCRIPTION'=>json_encode(['orderId'=>$orderId,'code'=>$code],JSON_THROW_ON_ERROR)]);
    }
    public static function safeCode(string $code): string { return preg_match('/\A(?:system|production|snapshot)_[a-z0-9_]{1,80}\z/D',$code) ? $code : 'system_order_sync_failed'; }
}
