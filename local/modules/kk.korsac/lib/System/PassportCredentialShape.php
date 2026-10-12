<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final class PassportCredentialShape
{
    public static function token(string $value): bool { return (bool)preg_match('/\A[a-f0-9]{32}\z/D',$value); }
}
