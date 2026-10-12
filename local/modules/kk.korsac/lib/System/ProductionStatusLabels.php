<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Throwable;
final class ProductionStatusLabels
{
    public function __construct(private readonly SystemSettingsGatewayInterface $gateway) {}
    public function all(): array
    {
        try {
            $labels=ProductionStatusRegistry::LABELS;
            foreach ($labels as $code=>&$label) $label=$this->gateway->get('system_production_label_'.$code,$label);
            return $labels;
        } catch (Throwable) { throw new SystemException('system_production_config_failed'); }
    }
    public function set(string $code, string $label): void
    {
        ProductionStatusRegistry::validate($code);
        if (!mb_check_encoding($label,'UTF-8') || trim($label)==='' || mb_strlen($label,'UTF-8')>255 || preg_match('/[\x00-\x1f\x7f]/u',$label)) throw new SystemException('system_production_label_invalid');
        try { $this->gateway->set('system_production_label_'.$code,$label); }
        catch (Throwable) { throw new SystemException('system_production_config_failed'); }
    }
}
