<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Throwable;
/** Persistent D7 post-save entry; projection/business logic are separate. */
final class SaleOrderSavedHandler
{
    public static function onOrderSaved($event): void
    {
        $order=null;
        try {
            $order=$event->getParameter('ENTITY');
            if (!is_object($order)) throw new SystemException('system_order_projection_failed');
            SystemServiceFactory::automation()->handle($order);
        } catch (Throwable $error) {
            try { (new BitrixSaleSynchronizationLogger())->log(is_object($order) ? (int)$order->getId() : 0,BitrixSaleSynchronizationLogger::safeCode($error instanceof SystemException ? $error->machineCode : 'system_order_sync_failed')); }
            catch (Throwable) {}
        }
    }
}
