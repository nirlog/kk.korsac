<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Closure;
use DateTimeImmutable;
use KK\Korsac\System\BitrixPassportRepository;
use KK\Korsac\System\PassportCredentialShape;
use KK\Korsac\System\PassportRecord;
use KK\Korsac\System\PassportTable;
use KK\Korsac\System\SystemException;
use KK\Korsac\System\SystemTable;
use Throwable;

final class BitrixOwnershipGateway implements OwnershipGatewayInterface
{
    private bool $active=false;
    private ?OwnershipSystemState $locked=null;
    private bool $emptyHistory=false;
    private ?int $assignedUser=null;
    private ?DateTimeImmutable $assignedAt=null;
    private bool $appended=false;

    /** Optional connection seam for deterministic transaction/conditional-SQL tests. */
    public function __construct(private readonly ?Closure $connectionFactory=null) {}
    private function connection(): object { return $this->connectionFactory!==null ? ($this->connectionFactory)() : Application::getConnection(); }

    public function transaction(Closure $operation): mixed
    {
        if ($this->active) throw new SystemException('system_ownership_persist_failed');
        $started=false;
        try {
            $connection=$this->connection(); $connection->startTransaction(); $started=true; $this->active=true;
            $result=$operation();
            if ($this->assignedUser!==null && !$this->appended) throw new SystemException('system_ownership_persist_failed');
            $connection->commitTransaction(); $started=false;
            return $result;
        } catch (Throwable $error) {
            if ($started) { try { $connection->rollbackTransaction(); } catch (Throwable) {} }
            if ($error instanceof SystemException) throw new SystemException($error->machineCode);
            throw new SystemException('system_ownership_persist_failed');
        } finally {
            $this->active=false; $this->locked=null; $this->emptyHistory=false; $this->assignedUser=null; $this->assignedAt=null; $this->appended=false;
        }
    }

    public function identifySystem(string $publicId): ?int
    {
        if (!PassportCredentialShape::token($publicId)) return null;
        try {
            $row=PassportTable::getList(['select'=>['SYSTEM_INTERNAL_ID'],'filter'=>['=PUBLIC_ID'=>$publicId],'limit'=>1])->fetch();
            return $row ? (int)$row['SYSTEM_INTERNAL_ID'] : null;
        } catch (Throwable) { throw new SystemException('system_ownership_read_failed'); }
    }

    public function lockSystem(int $id): ?OwnershipSystemState
    {
        $this->requireTransaction();
        if (!OwnershipIdentity::valid($id) || ($this->locked!==null && $this->locked->systemInternalId!==$id)) throw new SystemException('system_ownership_state_invalid');
        try {
            $table=SystemTable::getTableName();
            $row=$this->connection()->query("SELECT `ID`,`SYSTEM_ID`,`USER_ID`,`LIFECYCLE_STATE`,`CREATED_AT`,`UPDATED_AT` FROM `{$table}` WHERE `ID`={$id} FOR UPDATE")->fetch();
            if (!$row) return null;
            $this->locked=new OwnershipSystemState((int)$row['ID'],(string)$row['SYSTEM_ID'],$row['USER_ID']===null ? null : (int)$row['USER_ID'],(string)$row['LIFECYCLE_STATE'],self::date($row['CREATED_AT']),self::date($row['UPDATED_AT']));
            return $this->locked;
        } catch (SystemException $error) { throw new SystemException($error->machineCode); }
        catch (Throwable) { throw new SystemException('system_ownership_read_failed'); }
    }

    public function lockPassport(int $id): ?PassportRecord
    {
        $this->requireParent($id);
        try { return (new BitrixPassportRepository())->lockBySystem($id); }
        catch (SystemException $error) { throw new SystemException($error->machineCode==='system_passport_state_invalid' ? 'system_ownership_state_invalid' : 'system_ownership_read_failed'); }
        catch (Throwable) { throw new SystemException('system_ownership_read_failed'); }
    }

    public function latestHistory(int $id): ?OwnershipHistoryRecord
    {
        $this->requireParent($id);
        try {
            $table=OwnershipHistoryTable::getTableName();
            $row=$this->connection()->query("SELECT * FROM `{$table}` WHERE `SYSTEM_INTERNAL_ID`={$id} ORDER BY `REVISION` DESC LIMIT 1 FOR UPDATE")->fetch();
            $record=$row ? self::hydrate($row) : null;
            $this->emptyHistory=$record===null;
            return $record;
        } catch (SystemException $error) { throw new SystemException($error->machineCode); }
        catch (Throwable) { throw new SystemException('system_ownership_read_failed'); }
    }

    public function claimUnownedSystem(int $id,int $userId,DateTimeImmutable $at): void
    {
        $this->requireParent($id);
        if (!OwnershipIdentity::valid($userId) || $this->locked->userId!==null || !$this->emptyHistory || $this->assignedUser!==null || $at->format('u')!=='000000') throw new SystemException('system_ownership_state_invalid');
        try {
            $connection=$this->connection(); $table=SystemTable::getTableName();
            $time=$connection->getSqlHelper()->forSql($at->format('Y-m-d H:i:s'));
            $connection->queryExecute("UPDATE `{$table}` SET `USER_ID`={$userId},`UPDATED_AT`='{$time}' WHERE `ID`={$id} AND `USER_ID` IS NULL AND `LIFECYCLE_STATE`='ACTIVE'");
            if ($connection->getAffectedRowsCount()!==1) throw new SystemException('system_ownership_persist_failed');
            $this->assignedUser=$userId; $this->assignedAt=$at;
        } catch (Throwable) { throw new SystemException('system_ownership_persist_failed'); }
    }

    public function appendClaim(OwnershipHistoryRecord $record): void
    {
        $this->requireParent($record->systemInternalId); $record->assertValid();
        if ($this->appended || $this->assignedUser===null || $record->toUserId!==$this->assignedUser || $record->createdAt!=$this->assignedAt || $record->id!==0 || $record->revision!==1) throw new SystemException('system_ownership_state_invalid');
        try {
            $result=OwnershipHistoryTable::add([
                'SYSTEM_INTERNAL_ID'=>$record->systemInternalId,'REVISION'=>$record->revision,'FROM_USER_ID'=>null,'TO_USER_ID'=>$record->toUserId,
                'EVENT_CODE'=>'CLAIMED','SOURCE_CODE'=>'SECRET_CLAIM','ACTOR_USER_ID'=>$record->actorUserId,
                'CREATED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($record->createdAt)),
            ]);
            if (!$result->isSuccess() || !OwnershipIdentity::valid((int)$result->getId())) throw new SystemException('system_ownership_persist_failed');
            $this->appended=true;
        } catch (Throwable) { throw new SystemException('system_ownership_persist_failed'); }
    }

    public function historyForSystem(int $id): array
    {
        $this->requireParent($id);
        try {
            $rows=OwnershipHistoryTable::getList(['filter'=>['=SYSTEM_INTERNAL_ID'=>$id],'order'=>['REVISION'=>'ASC']]); $records=[];
            while ($row=$rows->fetch()) $records[]=self::hydrate($row);
            return $records;
        } catch (SystemException $error) { throw new SystemException($error->machineCode); }
        catch (Throwable) { throw new SystemException('system_ownership_read_failed'); }
    }

    private function requireTransaction(): void { if (!$this->active) throw new SystemException('system_ownership_persist_failed'); }
    private function requireParent(int $id): void
    {
        $this->requireTransaction();
        if ($this->locked===null || $this->locked->systemInternalId!==$id) throw new SystemException('system_ownership_state_invalid');
    }
    private static function hydrate(array $row): OwnershipHistoryRecord
    {
        $record=new OwnershipHistoryRecord((int)$row['ID'],(int)$row['SYSTEM_INTERNAL_ID'],(int)$row['REVISION'],$row['FROM_USER_ID']===null ? null : (int)$row['FROM_USER_ID'],$row['TO_USER_ID']===null ? null : (int)$row['TO_USER_ID'],(string)$row['EVENT_CODE'],(string)$row['SOURCE_CODE'],$row['ACTOR_USER_ID']===null ? null : (int)$row['ACTOR_USER_ID'],self::date($row['CREATED_AT']));
        $record->assertValid();
        if ($record->id<1) throw new SystemException('system_ownership_state_invalid');
        return $record;
    }
    private static function date(mixed $value): DateTimeImmutable
    {
        $text=is_object($value) ? $value->format('Y-m-d H:i:s') : (string)$value;
        $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$text); $errors=DateTimeImmutable::getLastErrors();
        if (!$time || ($errors!==false && ($errors['warning_count'] || $errors['error_count'])) || (int)$time->format('Y')<1000) throw new SystemException('system_ownership_state_invalid');
        return $time;
    }
}
