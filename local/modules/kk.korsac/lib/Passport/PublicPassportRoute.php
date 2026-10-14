<?php

declare(strict_types=1);

namespace KK\Korsac\Passport;

use KK\Korsac\System\PassportPath;

final class PublicPassportRoute
{
    public const COMPONENT='kk:korsac.passport';
    // Some Bitrix runtimes match REQUEST_URI including its query. Only the query tail is optional.
    public const CONDITION='#\A/passport/([a-f0-9]{32})/?(?:\?[^\#\r\n]*)?\z#D';
    public const ENDPOINT='/passport/index.php';

    public static function rewrite(): array
    {
        return ['CONDITION'=>self::CONDITION, 'RULE'=>'PUBLIC_ID=$1', 'ID'=>self::COMPONENT, 'PATH'=>self::ENDPOINT];
    }

    /** Inspect only the original path; never decode it or read GET/POST credentials. */
    public static function publicId(string $requestUri): string
    {
        $path=explode('?', $requestUri, 2)[0];
        return preg_match(self::CONDITION, $path, $matches)===1 ? $matches[1] : '';
    }

    public static function redirect(string $requestUri): ?string
    {
        $id=self::publicId($requestUri);
        $path=explode('?', $requestUri, 2)[0];
        return $id!=='' && !str_ends_with($path, '/') ? PassportPath::build($id) : null;
    }
}
