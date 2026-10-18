<?php

declare(strict_types=1);

namespace KK\Korsac\MySystems;

use Bitrix\Main\Application;
use Closure;
use KK\Korsac\Component\ComponentRowMapper;
use KK\Korsac\Component\ComponentTime;
use KK\Korsac\Install\OwnershipSchemaDefinition;
use KK\Korsac\Install\PassportSchemaDefinition;
use KK\Korsac\Install\SystemSchemaDefinition;
use KK\Korsac\Ownership\OwnershipHistoryRecord;
use KK\Korsac\System\PassportRecord;
use KK\Korsac\System\SystemException;
use Throwable;

/** One non-locking SELECT: bounded parents and their validation inputs in one InnoDB snapshot. */
final class BitrixMySystemsGateway implements MySystemsGatewayInterface
{
    private const FIELDS=[
        'S'=>['ID','USER_ID','SYSTEM_ID','SYSTEM_TYPE','MODEL_NAME','LIFECYCLE_STATE','CREATED_AT','UPDATED_AT'],
        'P'=>['SYSTEM_INTERNAL_ID','PUBLIC_ID','SECRET_CODE_HASH','STATE','TRANSFERRED_AT','WARRANTY_STARTED_AT','PREPARED_AT','UPDATED_AT'],
        'C'=>['ID','SYSTEM_INTERNAL_ID','TYPE_CODE','SLOT_KEY','SLOT_LABEL','NAME','SERIAL_NUMBER','INSTALLED_AT','REMOVED_AT','CREATED_AT','UPDATED_AT'],
        'H'=>['ID','SYSTEM_INTERNAL_ID','REVISION','FROM_USER_ID','TO_USER_ID','EVENT_CODE','SOURCE_CODE','ACTOR_USER_ID','CREATED_AT'],
    ];
    public function __construct(private readonly ?Closure $connectionFactory=null) {}
    public function findForUser(#[\SensitiveParameter] int $userId,MySystemsPageRequest $page): array
    {
        if ($userId<1 || $userId>2147483647) throw new SystemException('my_systems_user_invalid');
        $select=[];
        foreach (self::FIELDS as $prefix=>$fields) foreach ($fields as $field) $select[]=strtolower($prefix).'.`'.$field.'` AS `'.$prefix.'_'.$field.'`';
        $systems=SystemSchemaDefinition::SYSTEM; $components=SystemSchemaDefinition::COMPONENT;
        $passports=PassportSchemaDefinition::TABLE; $history=OwnershipSchemaDefinition::HISTORY;
        $limit=$page->limit+1; $offset=$page->offset;
        $source=implode(',',array_map(static fn(string $field):string=>'`'.$field.'`',self::FIELDS['S']));
        $sql='SELECT '.implode(',',$select)." FROM (
            SELECT {$source} FROM `{$systems}` WHERE `USER_ID`={$userId}
            ORDER BY `CREATED_AT` DESC,`SYSTEM_ID` DESC LIMIT {$limit} OFFSET {$offset}
        ) s
        LEFT JOIN `{$passports}` p ON p.`SYSTEM_INTERNAL_ID`=s.`ID`
        LEFT JOIN `{$history}` h ON h.`ID`=(
            SELECT oh_latest.`ID` FROM `{$history}` oh_latest
            WHERE oh_latest.`SYSTEM_INTERNAL_ID`=s.`ID` ORDER BY oh_latest.`REVISION` DESC LIMIT 1
        )
        LEFT JOIN `{$components}` c ON c.`SYSTEM_INTERNAL_ID`=s.`ID`
        ORDER BY s.`CREATED_AT` DESC,s.`SYSTEM_ID` DESC,c.`SLOT_KEY` ASC,c.`INSTALLED_AT` ASC,c.`ID` ASC";
        try {
            $connection=$this->connectionFactory!==null ? ($this->connectionFactory)() : Application::getConnection();
            $rows=$connection->query($sql); $records=[]; $group=[]; $key=null;
            while (($row=$rows->fetch())!==false) {
                if (!is_array($row) || !isset($row['S_ID'])) throw new SystemException('my_systems_state_invalid');
                $id=ComponentRowMapper::id($row['S_ID']);
                if ($key!==null && $key!==$id) { $records[]=$this->hydrate($group); $group=[]; }
                if (count($records)>=$limit) throw new SystemException('my_systems_state_invalid');
                $key=$id; $group[]=$row;
            }
            if ($group!==[]) $records[]=$this->hydrate($group);
            return $records;
        } catch (SystemException $error) {
            throw new SystemException(in_array($error->machineCode,['my_systems_state_invalid','system_component_state_invalid'],true) ? 'my_systems_state_invalid' : 'my_systems_read_failed');
        } catch (Throwable) { throw new SystemException('my_systems_read_failed'); }
    }
    private function hydrate(#[\SensitiveParameter] array $rows): MySystemReadRecord
    {
        try {
            $first=$rows[0]; $system=$this->fields($first,'S'); $passport=$this->fields($first,'P'); $history=$this->fields($first,'H'); $components=[];
            foreach ($rows as $row) {
                if ($system!==$this->fields($row,'S') || $passport!==$this->fields($row,'P') || $history!==$this->fields($row,'H')) throw new SystemException('my_systems_state_invalid');
                $component=$this->fields($row,'C');
                if ($this->present($component,'ID')) $components[]=ComponentRowMapper::component($component);
            }
            $p=null;
            if ($this->present($passport,'SYSTEM_INTERNAL_ID')) {
                $p=new PassportRecord(ComponentRowMapper::id($passport['SYSTEM_INTERNAL_ID']),$passport['PUBLIC_ID'],$passport['SECRET_CODE_HASH'],$passport['STATE'],$passport['TRANSFERRED_AT']===null ? null : ComponentTime::fromSql($passport['TRANSFERRED_AT']),$passport['WARRANTY_STARTED_AT']===null ? null : ComponentTime::fromSql($passport['WARRANTY_STARTED_AT']),ComponentTime::fromSql($passport['PREPARED_AT']),ComponentTime::fromSql($passport['UPDATED_AT']));
            }
            $h=null;
            if ($this->present($history,'ID')) {
                $optionalId=static fn(mixed $value):?int=>$value===null ? null : ComponentRowMapper::id($value);
                $h=new OwnershipHistoryRecord(ComponentRowMapper::id($history['ID']),ComponentRowMapper::id($history['SYSTEM_INTERNAL_ID']),ComponentRowMapper::id($history['REVISION']),$optionalId($history['FROM_USER_ID']),$optionalId($history['TO_USER_ID']),$history['EVENT_CODE'],$history['SOURCE_CODE'],$optionalId($history['ACTOR_USER_ID']),ComponentTime::fromSql($history['CREATED_AT']));
            }
            return new MySystemReadRecord(ComponentRowMapper::id($system['ID']),$system['USER_ID']===null ? null : ComponentRowMapper::id($system['USER_ID']),$system['SYSTEM_ID'],$system['SYSTEM_TYPE'],$system['MODEL_NAME'],$system['LIFECYCLE_STATE'],ComponentTime::fromSql($system['CREATED_AT']),ComponentTime::fromSql($system['UPDATED_AT']),$p,$components,$h);
        } catch (Throwable) { throw new SystemException('my_systems_state_invalid'); }
    }
    private function fields(#[\SensitiveParameter] array $row,string $prefix): array
    {
        $values=[];
        foreach (self::FIELDS[$prefix] as $field) {
            $key=$prefix.'_'.$field;
            if (!array_key_exists($key,$row)) throw new SystemException('my_systems_state_invalid');
            $values[$field]=$row[$key];
        }
        return $values;
    }
    private function present(#[\SensitiveParameter] array $row,string $identity): bool
    {
        if ($row[$identity]!==null) return true;
        foreach ($row as $value) if ($value!==null) throw new SystemException('my_systems_state_invalid');
        return false;
    }
}
