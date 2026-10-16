<?php

declare(strict_types=1);

namespace KK\Korsac\MySystems;

use KK\Korsac\System\SystemException;

final readonly class MySystemsPageRequest
{
    public const DEFAULT_LIMIT=50;
    public const MAX_LIMIT=100;
    public function __construct(public int $limit=self::DEFAULT_LIMIT,public int $offset=0)
    {
        if ($limit<1 || $limit>self::MAX_LIMIT || $offset<0) throw new SystemException('my_systems_page_invalid');
    }
}
