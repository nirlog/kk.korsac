<?php

declare(strict_types=1);

namespace KK\Korsac\MySystems;

use KK\Korsac\System\SystemException;

/** Trusted operator parsing only; never reuse --user as a customer HTTP identity contract. */
final readonly class MySystemsCliInput
{
    private function __construct(public int $userId,public MySystemsPageRequest $page) {}
    public static function fromOptions(#[\SensitiveParameter] array $options): self
    {
        if (array_diff(array_keys($options),['user','limit','offset'])) throw new SystemException('system_cli_arguments_invalid');
        $user=self::number($options['user'] ?? '', 'my_systems_user_invalid');
        if ($user<1 || $user>2147483647) throw new SystemException('my_systems_user_invalid');
        $limit=isset($options['limit']) ? self::number($options['limit'],'my_systems_page_invalid') : MySystemsPageRequest::DEFAULT_LIMIT;
        $offset=isset($options['offset']) ? self::number($options['offset'],'my_systems_page_invalid') : 0;
        return new self($user,new MySystemsPageRequest($limit,$offset));
    }
    private static function number(#[\SensitiveParameter] string $text,string $code): int
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$text) || filter_var($text,FILTER_VALIDATE_INT)===false) throw new SystemException($code);
        return (int)$text;
    }
}
