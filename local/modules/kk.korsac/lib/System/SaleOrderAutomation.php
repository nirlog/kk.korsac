<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Throwable;
final class SaleOrderAutomation
{
    public function __construct(private readonly SaleAutomationConfiguration $configuration,private readonly OrderProjectionAdapterInterface $adapter,private readonly OrderSystemSynchronizationServiceInterface $synchronizer,private readonly SaleSynchronizationLoggerInterface $logger) {}
    public function handle(object $order): void
    {
        try {
            $settings=$this->configuration->read();
            if (!$settings->enabled || $settings->triggerStatusId==='' || (string)$order->getField('STATUS_ID')!==$settings->triggerStatusId) return;
            $this->synchronizer->synchronize($this->adapter->project($order));
        } catch (Throwable $error) {
            try {
                $id=(int)$order->getId();
                $this->logger->log($id>=1 && $id<=2147483647 ? $id : 0,BitrixSaleSynchronizationLogger::safeCode($error instanceof SystemException ? $error->machineCode : 'system_order_sync_failed'));
            } catch (Throwable) {} // Logger failures cannot change the saved Order outcome either.
        }
    }
}
