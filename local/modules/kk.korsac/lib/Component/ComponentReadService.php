<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use KK\Korsac\System\SystemComponent;
use KK\Korsac\System\SystemException;
use Throwable;

final class ComponentReadService
{
    public function __construct(private readonly ComponentGatewayInterface $gateway) {}
    /** @return list<ComponentCurrentView> */
    public function current(int $id): array { return $this->read($id,true); }
    /** @return list<ComponentCurrentView> */
    public function history(int $id): array { return $this->read($id,false); }
    private function read(int $id,bool $activeOnly): array
    {
        ComponentSystemState::assertId($id);
        try {
            return $this->gateway->transaction(function() use ($id,$activeOnly): array {
                $system=$this->gateway->lockSystem($id);
                if ($system===null) throw new SystemException('system_component_not_found');
                if ($system->id!==$id) throw new SystemException('system_component_state_invalid');
                $records=$this->gateway->lockComponents($id);
                ComponentTimeline::assertValid($system,$records);
                $records=ComponentTimeline::ordered($records);
                if ($activeOnly) $records=array_values(array_filter($records,static fn(SystemComponent $row):bool=>$row->removedAt===null));
                return array_map(static fn(SystemComponent $row):ComponentCurrentView=>ComponentCurrentView::of($row),$records);
            });
        } catch (ComponentStateInvalid) { throw new SystemException('system_component_state_invalid'); }
        catch (SystemException $error) { throw new SystemException(in_array($error->machineCode,['system_component_not_found','system_component_state_invalid'],true) ? $error->machineCode : 'system_component_read_failed'); }
        catch (Throwable) { throw new SystemException('system_component_read_failed'); }
    }
}
