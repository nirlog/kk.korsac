<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use DateTimeImmutable;
final class PassportTimestamp
{
    public static function seconds(DateTimeImmutable $time): DateTimeImmutable { return $time->setTimestamp($time->getTimestamp()); }
    /** Explicit UTC offset and seconds precision match DATETIME storage without silent time loss. */
    public static function fromIso8601(string $value): DateTimeImmutable
    {
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])\z/D',$value)) throw new SystemException('system_passport_transfer_time_invalid');
        $time=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',str_ends_with($value,'Z') ? substr($value,0,-1).'+00:00' : $value);
        $errors=DateTimeImmutable::getLastErrors();
        if (!$time || ($errors!==false && ($errors['warning_count'] || $errors['error_count']))) throw new SystemException('system_passport_transfer_time_invalid');
        return $time;
    }
}
