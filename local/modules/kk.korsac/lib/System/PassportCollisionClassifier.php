<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\DB\SqlQueryException;
use Throwable;
final class PassportCollisionClassifier
{
    public static function isCredentialDuplicate(Throwable $error): bool
    {
        // Inspect only the driver's database reason, never the query; do not retain/log/return it.
        return $error instanceof SqlQueryException && (bool)preg_match(
            '/\bDuplicate\s+entry\b.*\bfor\s+key\s+[\'"`](?:[A-Za-z0-9_]+\.)?(?:ux_kk_korsac_passport_public_id|ux_kk_korsac_passport_secret_hash)[\'"`]/i',
            (string)$error->getDatabaseMessage(),
        );
    }
}
