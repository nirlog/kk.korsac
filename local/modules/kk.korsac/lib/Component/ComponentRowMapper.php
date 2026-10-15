<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use KK\Korsac\System\SystemComponent;
use KK\Korsac\System\SystemException;
use Throwable;

/** Infrastructure hydration shared by ORM reads, controlled SQL and data health. */
final class ComponentRowMapper
{
    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$value) || (int)$value>2147483647) throw new SystemException('system_component_state_invalid');
        return (int)$value;
    }
    public static function component(array $row): SystemComponent
    {
        try {
            foreach (['ID','SYSTEM_INTERNAL_ID','TYPE_CODE','SLOT_KEY','SLOT_LABEL','NAME','SERIAL_NUMBER','INSTALLED_AT','REMOVED_AT','CREATED_AT','UPDATED_AT'] as $key) if (!array_key_exists($key,$row)) throw new SystemException('system_component_state_invalid');
            foreach (['TYPE_CODE','SLOT_KEY','SLOT_LABEL','NAME'] as $key) if (!isset($row[$key]) || !is_string($row[$key])) throw new SystemException('system_component_state_invalid');
            if (!array_key_exists('SERIAL_NUMBER',$row) || ($row['SERIAL_NUMBER']!==null && !is_string($row['SERIAL_NUMBER']))) throw new SystemException('system_component_state_invalid');
            return new SystemComponent(self::id($row['ID']),self::id($row['SYSTEM_INTERNAL_ID']),$row['TYPE_CODE'],$row['SLOT_KEY'],$row['SLOT_LABEL'],$row['NAME'],$row['SERIAL_NUMBER'],ComponentTime::fromSql($row['INSTALLED_AT']),$row['REMOVED_AT']===null ? null : ComponentTime::fromSql($row['REMOVED_AT']),ComponentTime::fromSql($row['CREATED_AT']),ComponentTime::fromSql($row['UPDATED_AT']));
        } catch (Throwable) { throw new SystemException('system_component_state_invalid'); }
    }
}
