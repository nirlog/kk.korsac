<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Throwable;
final class SaleAutomationConfiguration
{
    public function __construct(private readonly SystemSettingsGatewayInterface $gateway) {}
    public function read(): SaleAutomationSettings
    {
        try {
            return new SaleAutomationSettings($this->gateway->get('system_sale_automation_enabled','N') === 'Y',$this->gateway->get('system_sale_trigger_status_id'));
        } catch (Throwable) { throw new SystemException('system_sale_config_failed'); }
    }
    public function configure(string $statusId): SaleAutomationSettings
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$statusId)) throw new SystemException('system_sale_status_invalid');
        try {
            if (!$this->gateway->isOrderStatus($statusId)) throw new SystemException('system_sale_status_invalid');
            // Disable during a two-option update; readers must never use an old trigger with new enablement.
            $this->gateway->set('system_sale_automation_enabled','N');
            $this->gateway->set('system_sale_trigger_status_id',$statusId);
            $this->gateway->set('system_sale_automation_enabled','Y');
            return $this->read();
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_sale_config_failed'); }
    }
    public function disable(): SaleAutomationSettings
    {
        try { $this->gateway->set('system_sale_automation_enabled','N'); return $this->read(); }
        catch (Throwable) { throw new SystemException('system_sale_config_failed'); }
    }
}
