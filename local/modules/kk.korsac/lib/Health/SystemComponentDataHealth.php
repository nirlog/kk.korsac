<?php

declare(strict_types=1);

namespace KK\Korsac\Health;

use Bitrix\Main\Application;
use Closure;
use KK\Korsac\Component\ComponentRowMapper;
use KK\Korsac\Component\ComponentStateInvalid;
use KK\Korsac\Component\ComponentSystemState;
use KK\Korsac\Component\ComponentTime;
use KK\Korsac\Component\ComponentTimeline;
use KK\Korsac\Install\SystemSchemaDefinition;
use Throwable;

/** Read-only scan, one System timeline in memory. Diagnostics are fixed categories/counts. */
final class SystemComponentDataHealth
{
    /** Optional ordered raw-row stream for deterministic data-health tests. */
    public function __construct(private readonly ?Closure $scan=null) {}
    public function run(): array
    {
        $counts=[]; $group=[]; $parentRow=null; $parentKey=null;
        try {
            foreach ($this->rows() as $row) {
                $key=(string)($row['SYSTEM_INTERNAL_ID'] ?? '');
                if ($parentRow!==null && $key!==$parentKey) { $this->inspect($parentRow,$group,$counts); $group=[]; }
                $parentKey=$key; $parentRow=$row; $group[]=$row;
            }
            if ($parentRow!==null) $this->inspect($parentRow,$group,$counts);
        } catch (Throwable) { return ['ok'=>false,'errors'=>[['code'=>'system_component_read_failed','category'=>'read','count'=>1]]]; }
        ksort($counts); $errors=[];
        foreach ($counts as $category=>$count) $errors[]=['code'=>'system_component_state_invalid','category'=>$category,'count'=>$count];
        return ['ok'=>$errors===[],'errors'=>$errors];
    }
    private function inspect(array $parentRow,array $group,array &$counts): void
    {
        if (($parentRow['PARENT_ID'] ?? null)===null) { $counts['orphan_parent']=($counts['orphan_parent'] ?? 0)+1; return; }
        try {
            $system=new ComponentSystemState(ComponentRowMapper::id($parentRow['PARENT_ID']),(string)$parentRow['PARENT_LIFECYCLE_STATE'],ComponentTime::fromSql($parentRow['PARENT_CREATED_AT']));
            $records=array_map(static fn(array $row)=>ComponentRowMapper::component($row),$group);
            ComponentTimeline::assertValid($system,$records);
        } catch (Throwable $error) {
            $category=$error instanceof ComponentStateInvalid ? $error->category : 'malformed';
            $counts[$category]=($counts[$category] ?? 0)+1;
        }
    }
    private function rows(): iterable
    {
        if ($this->scan!==null) { yield from ($this->scan)(); return; }
        $components=SystemSchemaDefinition::COMPONENT; $systems=SystemSchemaDefinition::SYSTEM;
        $rows=Application::getConnection()->query("SELECT c.*,s.`ID` AS PARENT_ID,s.`LIFECYCLE_STATE` AS PARENT_LIFECYCLE_STATE,s.`CREATED_AT` AS PARENT_CREATED_AT FROM `{$components}` c LEFT JOIN `{$systems}` s ON s.`ID`=c.`SYSTEM_INTERNAL_ID` ORDER BY c.`SYSTEM_INTERNAL_ID`,c.`SLOT_KEY`,c.`INSTALLED_AT`,c.`ID`");
        while ($row=$rows->fetch()) yield $row;
    }
}
