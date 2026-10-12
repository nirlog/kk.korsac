<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Bitrix\Main\Type\DateTime;
use DateTimeImmutable;
use Throwable;
final class BitrixSystemComponentRepository implements SystemComponentRepositoryInterface
{
    public function create(SystemComponent $component): SystemComponent
    {
        if ($component->id !== 0) throw new SystemException('system_component_invalid');
        try {
            if (!(new BitrixSystemRepository())->findById($component->systemId)) throw new SystemException('system_not_found');
            $result = SystemComponentTable::add([
                'SYSTEM_ID'=>$component->systemId, 'TYPE_CODE'=>$component->typeCode, 'SLOT_KEY'=>$component->slotKey,
                'SLOT_LABEL'=>$component->slotLabel, 'NAME'=>$component->name, 'SERIAL_NUMBER'=>$component->serialNumber,
                'INSTALLED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($component->installedAt)),
                'REMOVED_AT'=>$component->removedAt === null ? null : DateTime::createFromPhp(\DateTime::createFromImmutable($component->removedAt)),
                'CREATED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($component->createdAt)),
                'UPDATED_AT'=>DateTime::createFromPhp(\DateTime::createFromImmutable($component->updatedAt)),
            ]);
            if (!$result->isSuccess()) throw new SystemException('system_component_persist_failed');
            return new SystemComponent((int)$result->getId(), $component->systemId, $component->typeCode, $component->slotKey, $component->slotLabel, $component->name, $component->serialNumber, $component->installedAt, $component->removedAt, $component->createdAt, $component->updatedAt);
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_component_persist_failed'); }
    }
    public function findForSystem(int $systemId, bool $activeOnly = false): array
    {
        try {
            $filter = ['=SYSTEM_ID'=>$systemId];
            if ($activeOnly) $filter['=REMOVED_AT'] = null;
            $rows = SystemComponentTable::getList(['filter'=>$filter, 'order'=>['ID'=>'ASC']]);
            $components = [];
            while ($row = $rows->fetch()) {
                $date = static fn(object $value): DateTimeImmutable => new DateTimeImmutable($value->format('Y-m-d H:i:s'));
                $components[] = new SystemComponent((int)$row['ID'], (int)$row['SYSTEM_ID'], (string)$row['TYPE_CODE'], (string)$row['SLOT_KEY'], (string)$row['SLOT_LABEL'], (string)$row['NAME'], $row['SERIAL_NUMBER'] === null ? null : (string)$row['SERIAL_NUMBER'], $date($row['INSTALLED_AT']), $row['REMOVED_AT'] === null ? null : $date($row['REMOVED_AT']), $date($row['CREATED_AT']), $date($row['UPDATED_AT']));
            }
            return $components;
        } catch (Throwable) { throw new SystemException('system_component_read_failed'); }
    }
}
