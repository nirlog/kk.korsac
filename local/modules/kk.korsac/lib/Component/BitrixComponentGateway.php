<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use Bitrix\Main\Application;
use Closure;
use DateTimeImmutable;
use KK\Korsac\Install\SystemSchemaDefinition;
use KK\Korsac\System\SystemComponent;
use KK\Korsac\System\SystemException;
use Throwable;

/** Fixed lifecycle writes on one connection, parent and slot per transaction. */
final class BitrixComponentGateway implements ComponentGatewayInterface
{
    private ?object $connection=null;
    private ?ComponentSystemState $parent=null;
    private ?string $slot=null;
    /** @var ?list<SystemComponent> */
    private ?array $records=null;
    private ?SystemComponent $closed=null;
    private bool $inserted=false;
    private bool $failed=false;

    public function __construct(private readonly ?Closure $connectionFactory=null) {}
    public function transaction(Closure $operation): mixed
    {
        if ($this->connection!==null) throw new SystemException('system_component_persist_failed');
        $started=false;
        try {
            $this->connection=$this->connectionFactory!==null ? ($this->connectionFactory)() : Application::getConnection();
            $this->connection->startTransaction(); $started=true;
            $result=$operation();
            if ($this->failed || ($this->closed!==null && !$this->inserted)) throw new SystemException('system_component_persist_failed');
            $this->connection->commitTransaction(); $started=false;
            return $result;
        } catch (Throwable $error) {
            if ($started) { try { $this->connection->rollbackTransaction(); } catch (Throwable) {} }
            throw new SystemException($error instanceof ComponentStateInvalid ? 'system_component_state_invalid' : ($error instanceof SystemException ? $error->machineCode : 'system_component_persist_failed'));
        } finally {
            $this->connection=null; $this->parent=null; $this->slot=null; $this->records=null; $this->closed=null; $this->inserted=false; $this->failed=false;
        }
    }
    public function lockSystem(int $systemInternalId): ?ComponentSystemState
    {
        $this->requireTransaction(); ComponentSystemState::assertId($systemInternalId);
        if ($this->parent!==null) throw new SystemException('system_component_state_invalid');
        try {
            $table=SystemSchemaDefinition::SYSTEM;
            $row=$this->connection->query("SELECT `ID`,`LIFECYCLE_STATE`,`CREATED_AT` FROM `{$table}` WHERE `ID`={$systemInternalId} FOR UPDATE")->fetch();
            if (!$row) return null;
            $parent=new ComponentSystemState(ComponentRowMapper::id($row['ID']),(string)$row['LIFECYCLE_STATE'],ComponentTime::fromSql($row['CREATED_AT']));
            if ($parent->id!==$systemInternalId) throw new SystemException('system_component_state_invalid');
            return $this->parent=$parent;
        } catch (SystemException|ComponentStateInvalid) { throw new SystemException('system_component_state_invalid'); }
        catch (Throwable) { throw new SystemException('system_component_read_failed'); }
    }
    public function lockComponents(int $systemInternalId,?string $slotKey=null): array
    {
        $this->requireParent($systemInternalId);
        if ($this->records!==null) throw new SystemException('system_component_state_invalid');
        if ($slotKey!==null) ComponentSpec::assertCode($slotKey,64);
        try {
            $table=SystemSchemaDefinition::COMPONENT;
            $rows=$this->connection->query("SELECT * FROM `{$table}` WHERE `SYSTEM_INTERNAL_ID`={$systemInternalId} ORDER BY `SLOT_KEY`,`INSTALLED_AT`,`ID` FOR UPDATE");
            $records=[]; while ($row=$rows->fetch()) $records[]=ComponentRowMapper::component($row);
            ComponentTimeline::assertValid($this->parent,$records);
            $this->slot=$slotKey; return $this->records=$records;
        } catch (SystemException|ComponentStateInvalid) { throw new SystemException('system_component_state_invalid'); }
        catch (Throwable) { throw new SystemException('system_component_read_failed'); }
    }
    public function closeComponent(int $systemInternalId,int $expectedComponentId,string $slotKey,DateTimeImmutable $at): void
    {
        $this->requireMutation($systemInternalId,$slotKey); ComponentSystemState::assertId($expectedComponentId); ComponentTime::assertValid($at);
        $current=ComponentTimeline::current(ComponentTimeline::slot($this->records,$slotKey));
        if ($this->closed!==null || $this->inserted || $current===null || $current->id!==$expectedComponentId || $at<$current->installedAt || $at<$this->parent->createdAt) throw new SystemException('system_component_state_invalid');
        try {
            $table=SystemSchemaDefinition::COMPONENT; $time=$this->quote($at->format('Y-m-d H:i:s')); $slot=$this->quote($slotKey);
            $this->connection->queryExecute("UPDATE `{$table}` SET `REMOVED_AT`={$time},`UPDATED_AT`={$time} WHERE `ID`={$expectedComponentId} AND `SYSTEM_INTERNAL_ID`={$systemInternalId} AND `SLOT_KEY`={$slot} AND `REMOVED_AT` IS NULL");
            if ($this->connection->getAffectedRowsCount()!==1) throw new SystemException('system_component_persist_failed');
            $this->closed=$current;
            foreach ($this->records as $i=>$record) if ($record->id===$current->id) $this->records[$i]=new SystemComponent($record->id,$systemInternalId,$record->typeCode,$slotKey,$record->slotLabel,$record->name,$record->serialNumber,$record->installedAt,$at,$record->createdAt,$at);
        } catch (Throwable) { $this->failed=true; throw new SystemException('system_component_persist_failed'); }
    }
    public function insertComponent(int $systemInternalId,#[\SensitiveParameter] ComponentSpec $spec,DateTimeImmutable $at): SystemComponent
    {
        $this->requireMutation($systemInternalId,$spec->slotKey); ComponentTime::assertValid($at);
        if ($this->inserted || $at<$this->parent->createdAt) throw new SystemException('system_component_state_invalid');
        $slot=ComponentTimeline::slot($this->records,$spec->slotKey); $last=$slot===[] ? null : $slot[count($slot)-1];
        if (ComponentTimeline::current($slot)!==null || ($last!==null && ($last->typeCode!==$spec->typeCode || $last->slotLabel!==$spec->slotLabel || $at<$last->removedAt))) throw new SystemException('system_component_state_invalid');
        if ($this->closed!==null && ($at!=$last->removedAt || ($this->closed->serialNumber!==null && $this->closed->serialNumber===$spec->serialNumber))) throw new SystemException('system_component_state_invalid');
        foreach ($this->records as $row) if ($row->removedAt===null && $spec->serialNumber!==null && $row->serialNumber===$spec->serialNumber) throw new SystemException('system_component_serial_conflict');
        try {
            $table=SystemSchemaDefinition::COMPONENT; $time=$this->quote($at->format('Y-m-d H:i:s'));
            $type=$this->quote($spec->typeCode); $slotKey=$this->quote($spec->slotKey); $label=$this->quote($spec->slotLabel); $name=$this->quote($spec->name); $serial=$spec->serialNumber===null ? 'NULL' : $this->quote($spec->serialNumber);
            $this->connection->queryExecute("INSERT INTO `{$table}` (`SYSTEM_INTERNAL_ID`,`TYPE_CODE`,`SLOT_KEY`,`SLOT_LABEL`,`NAME`,`SERIAL_NUMBER`,`INSTALLED_AT`,`REMOVED_AT`,`CREATED_AT`,`UPDATED_AT`) VALUES ({$systemInternalId},{$type},{$slotKey},{$label},{$name},{$serial},{$time},NULL,{$time},{$time})");
            if ($this->connection->getAffectedRowsCount()!==1) throw new SystemException('system_component_persist_failed');
            $id=ComponentRowMapper::id($this->connection->getInsertedId());
            $record=new SystemComponent($id,$systemInternalId,$spec->typeCode,$spec->slotKey,$spec->slotLabel,$spec->name,$spec->serialNumber,$at,null,$at,$at);
            $this->records[]=$record; ComponentTimeline::assertValid($this->parent,$this->records); $this->inserted=true;
            return $record;
        } catch (Throwable) { $this->failed=true; throw new SystemException('system_component_persist_failed'); }
    }
    private function quote(string $text): string { return "'".$this->connection->getSqlHelper()->forSql($text)."'"; }
    private function requireTransaction(): void { if ($this->connection===null) throw new SystemException('system_component_persist_failed'); }
    private function requireParent(int $id): void
    {
        $this->requireTransaction();
        if ($this->parent===null || $this->parent->id!==$id) throw new SystemException('system_component_state_invalid');
    }
    private function requireMutation(int $id,string $slot): void
    {
        $this->requireParent($id);
        if ($this->parent->lifecycleState!=='ACTIVE' || $this->records===null || $this->slot===null || $this->slot!==$slot) throw new SystemException('system_component_state_invalid');
    }
}
