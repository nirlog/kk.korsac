<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Throwable;
final class SaleHandlerRegistration
{
    public function __construct(private readonly SaleHandlerRegistrationGatewayInterface $gateway) {}
    public function ensure(): void
    {
        try {
            if ($this->gateway->count()===1) return;
            $this->gateway->unregister(); $this->gateway->register();
            if ($this->gateway->count()!==1) throw new SystemException('system_sale_handler_registration_failed');
        } catch (Throwable) { throw new SystemException('system_sale_handler_registration_failed'); }
    }
    public function remove(): void
    {
        try { $this->gateway->unregister(); }
        catch (Throwable) { throw new SystemException('system_sale_handler_registration_failed'); }
    }
    public function check(): array
    {
        try { $count=$this->gateway->count(); return ['ok'=>$count===1,'errors'=>$count===1 ? [] : [['code'=>$count===0 ? 'system_sale_handler_missing' : 'system_sale_handler_duplicate']]]; }
        catch (Throwable) { return ['ok'=>false,'errors'=>[['code'=>'system_sale_handler_read_failed']]]; }
    }
}
