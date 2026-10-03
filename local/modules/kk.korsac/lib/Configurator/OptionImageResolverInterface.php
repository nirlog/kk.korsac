<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

interface OptionImageResolverInterface
{
    /** @return array{src:string,width:int,height:int}|null */
    public function resolve(mixed $fileId): ?array;
}
