<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use KK\Korsac\Component\ComponentRowMapper;
use KK\Korsac\Component\ComponentSystemState;
use Throwable;
final class BitrixSystemComponentRepository implements SystemComponentRepositoryInterface
{
    public function findForSystem(int $systemInternalId, bool $activeOnly = false): array
    {
        ComponentSystemState::assertId($systemInternalId);
        try {
            $filter = ['=SYSTEM_INTERNAL_ID'=>$systemInternalId];
            if ($activeOnly) $filter['=REMOVED_AT'] = null;
            $rows = SystemComponentTable::getList(['filter'=>$filter, 'order'=>$activeOnly ? ['SLOT_KEY'=>'ASC','ID'=>'ASC'] : ['SLOT_KEY'=>'ASC','INSTALLED_AT'=>'ASC','ID'=>'ASC']]);
            $components = [];
            while ($row = $rows->fetch()) {
                $components[] = ComponentRowMapper::component($row);
            }
            return $components;
        } catch (Throwable) { throw new SystemException('system_component_read_failed'); }
    }
}
