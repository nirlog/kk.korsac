<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use Bitrix\Main\SiteTable;
use KK\Korsac\System\SystemException;
use Throwable;

final class PassportDeliveryFactory
{
    public static function create(?string $siteId=null): PassportDeliveryService
    {
        try {
            if ($siteId===null) {
                $current=defined('SITE_ID') ? constant('SITE_ID') : null;
                if (is_string($current) && preg_match('/\A[a-zA-Z0-9]{2}\z/D', $current)) $siteId=$current;
                else {
                    $rows=SiteTable::getList(['filter'=>['=ACTIVE'=>'Y', '=DEF'=>'Y'], 'select'=>['LID'], 'limit'=>2])->fetchAll();
                    if (count($rows)!==1) throw new SystemException('passport_delivery_site_required');
                    $siteId=(string)$rows[0]['LID'];
                }
            }
            return new PassportDeliveryService(new BitrixPassportDeliveryGateway($siteId, (string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('passport_delivery_read_failed'); }
    }
}
