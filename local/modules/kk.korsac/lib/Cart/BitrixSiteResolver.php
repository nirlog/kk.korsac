<?php
declare(strict_types=1);
namespace KK\Korsac\Cart;
final class BitrixSiteResolver implements SiteResolverInterface
{
    public function currentSiteId(): string
    {
        $siteId = defined('SITE_ID') ? trim((string)constant('SITE_ID')) : '';
        if ($siteId === '' || strlen($siteId) > 10) throw new CartException(['code'=>'site_not_available']);
        return $siteId;
    }
}
