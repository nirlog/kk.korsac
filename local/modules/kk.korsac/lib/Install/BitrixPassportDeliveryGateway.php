<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use Bitrix\Main\UrlRewriter;
use KK\Korsac\Passport\PublicPassportRoute;
use KK\Korsac\System\SystemException;
use Throwable;

final class BitrixPassportDeliveryGateway implements PassportDeliveryGatewayInterface
{
    private readonly PassportDeliveryFiles $files;
    public function __construct(private readonly string $siteId, string $documentRoot)
    {
        if (!preg_match('/\A[a-zA-Z0-9]{2}\z/D', $siteId)) throw new SystemException('passport_delivery_site_invalid');
        $this->files=new PassportDeliveryFiles($documentRoot);
    }
    public function fileState(string $file): string { return $this->files->state($file); }
    public function installFile(string $file): void { $this->files->install($file); }
    public function removeFile(string $file): void { $this->files->remove($file); }
    public function routes(): array
    {
        try { return UrlRewriter::getList($this->siteId); }
        catch (Throwable) { throw new SystemException('passport_delivery_read_failed'); }
    }
    public function replaceOwnedRoute(?array $route): void
    {
        if ($route!==null && $route!==PublicPassportRoute::rewrite()) throw new SystemException('passport_delivery_route_invalid');
        try {
            UrlRewriter::delete($this->siteId, ['ID'=>PublicPassportRoute::COMPONENT]);
            if ($route!==null) UrlRewriter::add($this->siteId, $route);
        } catch (Throwable) { throw new SystemException('passport_delivery_write_failed'); }
    }
}
