<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

interface PassportDeliveryGatewayInterface
{
    /** missing, owned, outdated, conflict; includes regular-file/source ownership checks. */
    public function fileState(string $file): string;
    public function installFile(string $file): void;
    public function removeFile(string $file): void;
    public function routes(): array;
    /** Replace/remove only routes whose ID is kk:korsac.passport. */
    public function replaceOwnedRoute(?array $route): void;
}
