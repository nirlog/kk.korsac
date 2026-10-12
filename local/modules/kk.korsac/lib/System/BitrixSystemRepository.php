<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Application;
use Throwable;
use DateTimeImmutable;
final class BitrixSystemRepository implements SystemRepositoryInterface
{
    public function create(SystemRecord $system): SystemRecord
    {
        try { return $this->persist($system); }
        catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_persist_failed'); }
    }
    private function persist(SystemRecord $system): SystemRecord
    {
        $s = $system->source;
        if ($system->id !== 0 || $system->userId !== null || $system->lifecycleState !== 'ACTIVE' || !preg_match('/\AKS-[0-9]{2}(?:0[1-9]|1[0-2])-[0-9]{4}\z/D', $system->systemId) || str_ends_with($system->systemId, '-0000')) {
            throw new SystemException('system_source_invalid');
        }
        $result = SystemTable::add([
            'SYSTEM_ID'=>$system->systemId, 'SITE_ID'=>$s->siteId, 'SYSTEM_TYPE'=>$s->systemType, 'MODEL_NAME'=>$s->modelName,
            'IBLOCK_ID'=>$s->iblockId, 'PRODUCT_ID'=>$s->productId, 'ORDER_ID'=>$s->orderId, 'ORDER_BASKET_ID'=>$s->orderBasketId,
            'ORDER_UNIT_INDEX'=>$s->orderUnitIndex, 'CONFIG_SNAPSHOT_KEY'=>$s->snapshotKey, 'USER_ID'=>null, 'LIFECYCLE_STATE'=>'ACTIVE',
            'CREATED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($system->createdAt)), 'UPDATED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($system->updatedAt)),
        ]);
        if (!$result->isSuccess()) throw new SystemException('system_persist_failed');
        return new SystemRecord((int)$result->getId(), $system->systemId, $s, null, 'ACTIVE', $system->createdAt, $system->updatedAt);
    }
    public function findById(int $id): ?SystemRecord { return $this->find(['=ID'=>$id]); }
    public function findBySystemId(string $systemId): ?SystemRecord { return $this->find(['=SYSTEM_ID'=>$systemId]); }
    public function findByOrderUnit(int $orderId, int $basketId, int $unitIndex, bool $currentRead = false): ?SystemRecord
    {
        if ($currentRead) {
            // A locking/current read sees the committed winner even under REPEATABLE READ.
            try {
                $table = SystemTable::getTableName();
                $row = Application::getConnection()->query("SELECT * FROM `{$table}` WHERE `ORDER_ID`={$orderId} AND `ORDER_BASKET_ID`={$basketId} AND `ORDER_UNIT_INDEX`={$unitIndex} FOR UPDATE")->fetch();
                if (!$row) return null;
                foreach (['CREATED_AT','UPDATED_AT'] as $key) {
                    if (!is_object($row[$key])) $row[$key] = new DateTime((string)$row[$key], 'Y-m-d H:i:s');
                }
                return $this->hydrate($row);
            } catch (Throwable) { throw new SystemException('system_persist_failed'); }
        }
        return $this->find(['=ORDER_ID'=>$orderId, '=ORDER_BASKET_ID'=>$basketId, '=ORDER_UNIT_INDEX'=>$unitIndex]);
    }
    public function findByOrder(int $orderId): array { return $this->findMany(['=ORDER_ID'=>$orderId]); }
    public function findByOrderBasket(int $orderId,int $basketId): array { return $this->findMany(['=ORDER_ID'=>$orderId,'=ORDER_BASKET_ID'=>$basketId]); }
    private function findMany(array $filter): array
    {
        try {
            $rows=SystemTable::getList(['filter'=>$filter,'order'=>['ORDER_BASKET_ID'=>'ASC','ORDER_UNIT_INDEX'=>'ASC']]); $systems=[];
            while ($row=$rows->fetch()) $systems[]=$this->hydrate($row);
            return $systems;
        } catch (Throwable) { throw new SystemException('system_persist_failed'); }
    }
    private function find(array $filter): ?SystemRecord
    {
        try {
            $row = SystemTable::getList(['filter'=>$filter, 'limit'=>1])->fetch();
            return $row ? $this->hydrate($row) : null;
        } catch (Throwable) { throw new SystemException('system_persist_failed'); }
    }
    private function hydrate(array $row): SystemRecord
    {
        $source = SystemCreationRequest::fromArray(array_intersect_key($row, array_flip(['SITE_ID','SYSTEM_TYPE','MODEL_NAME','CONFIG_SNAPSHOT_KEY'])) + [
            'ORDER_ID'=>(int)$row['ORDER_ID'], 'ORDER_BASKET_ID'=>(int)$row['ORDER_BASKET_ID'], 'ORDER_UNIT_INDEX'=>(int)$row['ORDER_UNIT_INDEX'],
            'IBLOCK_ID'=>$row['IBLOCK_ID'] === null ? null : (int)$row['IBLOCK_ID'], 'PRODUCT_ID'=>$row['PRODUCT_ID'] === null ? null : (int)$row['PRODUCT_ID'],
        ]);
        return new SystemRecord((int)$row['ID'], (string)$row['SYSTEM_ID'], $source, $row['USER_ID'] === null ? null : (int)$row['USER_ID'], (string)$row['LIFECYCLE_STATE'],
            new DateTimeImmutable($row['CREATED_AT']->format('Y-m-d H:i:s')), new DateTimeImmutable($row['UPDATED_AT']->format('Y-m-d H:i:s')));
    }
}
