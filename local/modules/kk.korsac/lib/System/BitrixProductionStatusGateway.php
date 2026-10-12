<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Closure;
use DateTimeImmutable;
use Throwable;
final class BitrixProductionStatusGateway implements ProductionStatusGatewayInterface
{
    public function transaction(Closure $operation): ProductionStatusState
    {
        $connection=Application::getConnection(); $started=false;
        try { $connection->startTransaction(); $started=true; $result=$operation(); $connection->commitTransaction(); $started=false; return $result; }
        catch (Throwable $error) {
            if ($started) { try { $connection->rollbackTransaction(); } catch (Throwable) {} }
            if ($error instanceof SystemException) throw $error;
            throw new SystemException('system_production_persist_failed');
        }
    }
    public function lockCurrent(int $id): ?ProductionStatusState
    {
        $connection=Application::getConnection(); $parent=SystemTable::getTableName(); $table=ProductionStatusTable::getTableName();
        // Existing parent is the lock anchor: two first transitions cannot both observe an absent state.
        if (!$connection->query("SELECT `ID` FROM `{$parent}` WHERE `ID`={$id} FOR UPDATE")->fetch()) throw new SystemException('system_not_found');
        $row=$connection->query("SELECT * FROM `{$table}` WHERE `SYSTEM_INTERNAL_ID`={$id} FOR UPDATE")->fetch();
        return $row ? $this->state($row) : null;
    }
    public function writeCurrent(ProductionStatusState $state, bool $insert): void
    {
        $data=['STATUS_CODE'=>$state->statusCode,'REVISION'=>$state->revision,'UPDATED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($state->updatedAt))];
        $result=$insert ? ProductionStatusTable::add(['SYSTEM_INTERNAL_ID'=>$state->systemInternalId]+$data) : ProductionStatusTable::update($state->systemInternalId,$data);
        if (!$result->isSuccess()) throw new SystemException('system_production_persist_failed');
    }
    public function appendHistory(ProductionStatusHistory $h): void
    {
        $result=ProductionStatusHistoryTable::add(['SYSTEM_INTERNAL_ID'=>$h->systemInternalId,'REVISION'=>$h->revision,'FROM_STATUS_CODE'=>$h->fromStatusCode,'TO_STATUS_CODE'=>$h->toStatusCode,'SOURCE_CODE'=>$h->sourceCode,'ACTOR_USER_ID'=>$h->actorUserId,'CREATED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($h->createdAt))]);
        if (!$result->isSuccess()) throw new SystemException('system_production_persist_failed');
    }
    public function findCurrent(int $id): ?ProductionStatusState
    {
        try { $row=ProductionStatusTable::getByPrimary($id)->fetch(); return $row ? $this->state($row) : null; }
        catch (Throwable) { throw new SystemException('system_production_read_failed'); }
    }
    public function history(int $id): array
    {
        try {
            $rows=ProductionStatusHistoryTable::getList(['filter'=>['=SYSTEM_INTERNAL_ID'=>$id],'order'=>['REVISION'=>'ASC']]); $history=[];
            while ($r=$rows->fetch()) $history[]=new ProductionStatusHistory((int)$r['SYSTEM_INTERNAL_ID'],(int)$r['REVISION'],$r['FROM_STATUS_CODE']===null ? null : (string)$r['FROM_STATUS_CODE'],(string)$r['TO_STATUS_CODE'],(string)$r['SOURCE_CODE'],$r['ACTOR_USER_ID']===null ? null : (int)$r['ACTOR_USER_ID'],new DateTimeImmutable($r['CREATED_AT']->format('Y-m-d H:i:s')));
            return $history;
        } catch (Throwable) { throw new SystemException('system_production_read_failed'); }
    }
    private function state(array $row): ProductionStatusState
    {
        $time=$row['UPDATED_AT'];
        return new ProductionStatusState((int)$row['SYSTEM_INTERNAL_ID'],(string)$row['STATUS_CODE'],(int)$row['REVISION'],new DateTimeImmutable(is_object($time) ? $time->format('Y-m-d H:i:s') : (string)$time));
    }
}
