<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use KK\Korsac\System\SystemComponent;

final class ComponentTimeline
{
    /** @param list<SystemComponent> $records */
    public static function assertValid(ComponentSystemState $system,array $records): void
    {
        $slots=[]; $ids=[]; $serials=[];
        foreach ($records as $record) {
            if ($record->id<1 || $record->systemInternalId!==$system->id || isset($ids[$record->id]) || $record->installedAt<$system->createdAt) throw new ComponentStateInvalid('malformed');
            $ids[$record->id]=true; $slots[$record->slotKey][]=$record;
            if ($record->removedAt===null && $record->serialNumber!==null) {
                $key='serial:'.$record->serialNumber;
                if (isset($serials[$key])) throw new ComponentStateInvalid('active_serial_conflict');
                $serials[$key]=true;
            }
        }
        foreach ($slots as $rows) {
            $rows=self::ordered($rows); $first=$rows[0]; $previous=null; $active=0;
            foreach ($rows as $row) {
                if ($row->typeCode!==$first->typeCode || $row->slotLabel!==$first->slotLabel) throw new ComponentStateInvalid('slot_identity');
                if ($row->removedAt===null && ++$active>1) throw new ComponentStateInvalid('duplicate_active_slot');
                if ($previous!==null) {
                    if ($previous->removedAt===null) throw new ComponentStateInvalid('active_not_latest');
                    if ($previous->removedAt>$row->installedAt) throw new ComponentStateInvalid('temporal_overlap');
                }
                $previous=$row;
            }
        }
    }
    /** @param list<SystemComponent> $records @return list<SystemComponent> */
    public static function ordered(array $records): array
    {
        usort($records,static fn(SystemComponent $a,SystemComponent $b):int=>strcmp($a->slotKey,$b->slotKey) ?: ($a->installedAt<=>$b->installedAt) ?: ($a->id<=>$b->id));
        return $records;
    }
    /** @param list<SystemComponent> $records @return list<SystemComponent> */
    public static function slot(array $records,string $slot): array
    {
        return self::ordered(array_values(array_filter($records,static fn(SystemComponent $row):bool=>$row->slotKey===$slot)));
    }
    /** @param list<SystemComponent> $records */
    public static function current(array $records): ?SystemComponent
    {
        foreach ($records as $record) if ($record->removedAt===null) return $record;
        return null;
    }
}
