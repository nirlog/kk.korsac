<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use KK\Korsac\Passport\PublicPassportRoute;
use KK\Korsac\System\SystemException;
use Throwable;

final class PassportDeliveryService
{
    public function __construct(private readonly PassportDeliveryGatewayInterface $gateway) {}

    public function check(): array
    {
        $errors=[];
        try {
            foreach (PassportDeliveryDefinition::files() as $file=>$source) {
                $state=$this->gateway->fileState($file);
                if ($state!=='owned') $errors[]=['code'=>'passport_delivery_file_'.$state, 'artifact'=>$file];
            }
            $routes=$this->gateway->routes();
            $owned=self::owned($routes);
            if (count($owned)!==1) $errors[]=['code'=>$owned===[] ? 'passport_delivery_route_missing' : 'passport_delivery_route_duplicate'];
            foreach ($owned as $route) if (!self::exact($route)) $errors[]=['code'=>'passport_delivery_route_mismatch'];
            if (self::conflict($routes)) $errors[]=['code'=>'passport_delivery_route_conflict'];
        } catch (Throwable) { $errors[]=['code'=>'passport_delivery_read_failed']; }
        return ['ok'=>$errors===[], 'errors'=>$errors];
    }

    public function install(): array
    {
        try {
            // Complete conflict preflight before copying anything or changing executable routes.
            foreach (PassportDeliveryDefinition::files() as $file=>$source) {
                if ($this->gateway->fileState($file)==='conflict') throw new SystemException('passport_delivery_file_conflict');
            }
            $routes=$this->gateway->routes();
            if (self::conflict($routes)) throw new SystemException('passport_delivery_route_conflict');
            foreach (PassportDeliveryDefinition::files() as $file=>$source) $this->gateway->installFile($file);
            $owned=self::owned($routes);
            if (count($owned)!==1 || !self::exact($owned[0])) $this->gateway->replaceOwnedRoute(PublicPassportRoute::rewrite());
            $result=$this->check();
            if (!$result['ok']) throw new SystemException('passport_delivery_install_failed');
            return $result;
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('passport_delivery_install_failed'); }
    }

    public function remove(): void
    {
        try {
            $this->gateway->replaceOwnedRoute(null);
            if (self::owned($this->gateway->routes())!==[]) throw new SystemException('passport_delivery_remove_failed');
            foreach (PassportDeliveryDefinition::files() as $file=>$source) $this->gateway->removeFile($file);
        } catch (Throwable) { throw new SystemException('passport_delivery_remove_failed'); }
    }

    private static function owned(array $routes): array
    {
        return array_values(array_filter($routes, static fn(array $route): bool=>($route['ID'] ?? null)===PublicPassportRoute::COMPONENT));
    }
    private static function exact(array $route): bool
    {
        foreach (PublicPassportRoute::rewrite() as $key=>$value) if (($route[$key] ?? null)!==$value) return false;
        return true;
    }
    private static function conflict(array $routes): bool
    {
        foreach ($routes as $route) {
            if (($route['ID'] ?? null)!==PublicPassportRoute::COMPONENT && (($route['PATH'] ?? null)===PublicPassportRoute::ENDPOINT || ($route['CONDITION'] ?? null)===PublicPassportRoute::CONDITION)) return true;
        }
        return false;
    }
}
