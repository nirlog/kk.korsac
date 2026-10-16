<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use DateTimeImmutable;
use KK\Korsac\System\SystemException;

final class ComponentTime
{
    public static function seconds(DateTimeImmutable $at): DateTimeImmutable { return $at->setTimestamp($at->getTimestamp()); }
    public static function assertValid(DateTimeImmutable $at): void
    {
        if ($at->format('u')!=='000000' || (int)$at->format('Y')<1000 || (int)$at->format('Y')>9999) throw new SystemException('system_component_invalid');
    }
    public static function fromSql(mixed $value): DateTimeImmutable
    {
        $text=is_object($value) ? $value->format('Y-m-d H:i:s') : (string)$value;
        $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$text); $errors=DateTimeImmutable::getLastErrors();
        if (!$at || ($errors!==false && ($errors['warning_count'] || $errors['error_count']))) throw new SystemException('system_component_state_invalid');
        self::assertValid($at); return $at;
    }
}
