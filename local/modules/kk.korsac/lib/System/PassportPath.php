<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final class PassportPath
{
    public static function build(string $publicId): string
    {
        if (!PassportCredentialShape::token($publicId)) throw new SystemException('system_passport_not_found');
        return '/passport/'.$publicId.'/';
    }
}
