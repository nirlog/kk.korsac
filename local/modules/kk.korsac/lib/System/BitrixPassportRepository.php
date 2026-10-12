<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Closure;
use DateTimeImmutable;
use Throwable;
final class BitrixPassportRepository implements PassportRepositoryInterface
{
    public function transaction(Closure $operation): mixed
    {
        $connection=Application::getConnection(); $started=false;
        try { $connection->startTransaction(); $started=true; $result=$operation(); $connection->commitTransaction(); $started=false; return $result; }
        catch (Throwable $error) {
            if ($started) { try { $connection->rollbackTransaction(); } catch (Throwable) {} }
            if ($error instanceof SystemException) throw $error;
            throw new SystemException('system_passport_persist_failed');
        }
    }
    public function lockSystem(int $id): PassportSystemState
    {
        $table=SystemTable::getTableName();
        $row=Application::getConnection()->query("SELECT `ID`,`LIFECYCLE_STATE`,`CREATED_AT` FROM `{$table}` WHERE `ID`={$id} FOR UPDATE")->fetch();
        if (!$row) throw new SystemException('system_not_found');
        return new PassportSystemState((int)$row['ID'],(string)$row['LIFECYCLE_STATE'],self::date($row['CREATED_AT']));
    }
    public function lockBySystem(int $id): ?PassportRecord
    {
        $table=PassportTable::getTableName();
        $row=Application::getConnection()->query("SELECT * FROM `{$table}` WHERE `SYSTEM_INTERNAL_ID`={$id} FOR UPDATE")->fetch();
        return $row ? $this->hydrate($row) : null;
    }
    public function findBySystem(int $id): ?PassportRecord { return $this->find(['=SYSTEM_INTERNAL_ID'=>$id]); }
    public function findByPublicId(string $publicId): ?PassportRecord
    {
        if (!PassportCredentialShape::token($publicId)) return null;
        return $this->find(['=PUBLIC_ID'=>$publicId]);
    }
    public function create(PassportRecord $passport): PassportRecord
    {
        $passport->assertValid();
        if ($passport->state!=='PREPARED' || $passport->preparedAt!=$passport->updatedAt) throw new SystemException('system_passport_state_invalid');
        try {
            $result=PassportTable::add([
                'SYSTEM_INTERNAL_ID'=>$passport->systemInternalId,'PUBLIC_ID'=>$passport->publicId,'SECRET_CODE_HASH'=>$passport->secretCodeHash,
                'STATE'=>'PREPARED','TRANSFERRED_AT'=>null,'WARRANTY_STARTED_AT'=>null,
                'PREPARED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($passport->preparedAt)),
                'UPDATED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($passport->updatedAt)),
            ]);
            if (!$result->isSuccess()) throw new SystemException('system_passport_persist_failed');
            return $passport;
        } catch (Throwable $error) {
            if (PassportCollisionClassifier::isCredentialDuplicate($error)) throw new PassportCredentialCollision();
            if ($error instanceof SystemException) throw $error;
            throw new SystemException('system_passport_persist_failed');
        }
    }
    public function activate(int $id,DateTimeImmutable $transfer,DateTimeImmutable $updated): PassportRecord
    {
        try {
            $connection=Application::getConnection(); $helper=$connection->getSqlHelper(); $table=PassportTable::getTableName();
            $handoff=$helper->forSql($transfer->format('Y-m-d H:i:s')); $now=$helper->forSql($updated->format('Y-m-d H:i:s'));
            // Fixed field set, conditional one-way mutation; ordinary ORM update/delete are prohibited.
            $connection->queryExecute("UPDATE `{$table}` SET `STATE`='ACTIVE',`TRANSFERRED_AT`='{$handoff}',`WARRANTY_STARTED_AT`='{$handoff}',`UPDATED_AT`='{$now}' WHERE `SYSTEM_INTERNAL_ID`={$id} AND `STATE`='PREPARED' AND `TRANSFERRED_AT` IS NULL AND `WARRANTY_STARTED_AT` IS NULL");
            if ($connection->getAffectedRowsCount()!==1) throw new SystemException('system_passport_persist_failed');
            $record=$this->lockBySystem($id);
            if ($record===null) throw new SystemException('system_passport_persist_failed');
            return $record;
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_passport_persist_failed'); }
    }
    private function find(array $filter): ?PassportRecord
    {
        try { $row=PassportTable::getList(['filter'=>$filter,'limit'=>1])->fetch(); return $row ? $this->hydrate($row) : null; }
        catch (Throwable) { throw new SystemException('system_passport_read_failed'); }
    }
    private function hydrate(array $row): PassportRecord
    {
        $record=new PassportRecord((int)$row['SYSTEM_INTERNAL_ID'],(string)$row['PUBLIC_ID'],(string)$row['SECRET_CODE_HASH'],(string)$row['STATE'],
            $row['TRANSFERRED_AT']===null ? null : self::date($row['TRANSFERRED_AT']),$row['WARRANTY_STARTED_AT']===null ? null : self::date($row['WARRANTY_STARTED_AT']),self::date($row['PREPARED_AT']),self::date($row['UPDATED_AT']));
        $record->assertValid(); return $record;
    }
    private static function date(mixed $value): DateTimeImmutable { return new DateTimeImmutable(is_object($value) ? $value->format('Y-m-d H:i:s') : (string)$value); }
}
