<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final class ProductionStatusRegistry
{
    public const LABELS = [
        'WAREHOUSE'=>'Передан кладовщику', 'PROCUREMENT'=>'Передан в отдел снабжения',
        'MISSING_COMPONENTS'=>'Не хватает комплектующих', 'ASSEMBLY'=>'Отправлен на сборку',
        'READY_FOR_DELIVERY'=>'Готов к выдаче',
    ];
    public static function validate(string $code): void
    {
        if (!isset(self::LABELS[$code])) throw new SystemException('system_production_status_invalid');
    }
}
