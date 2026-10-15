<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use Closure;
use DateTimeImmutable;
use KK\Korsac\System\SystemException;
use Throwable;

final class SystemComponentService
{
    public function __construct(private readonly ComponentGatewayInterface $gateway,private readonly ?Closure $clock=null) {}
    public function install(int $systemInternalId,#[\SensitiveParameter] ComponentSpec $component): ComponentInstallResult { return $this->mutate($systemInternalId,$component,null); }
    public function replace(int $systemInternalId,int $expectedComponentId,#[\SensitiveParameter] ComponentSpec $replacement): ComponentReplaceResult
    {
        ComponentSystemState::assertId($expectedComponentId);
        return $this->mutate($systemInternalId,$replacement,$expectedComponentId);
    }
    private function mutate(int $id,#[\SensitiveParameter] ComponentSpec $spec,?int $expected): ComponentInstallResult|ComponentReplaceResult
    {
        ComponentSystemState::assertId($id);
        try {
            return $this->gateway->transaction(function() use ($id,$spec,$expected): ComponentInstallResult|ComponentReplaceResult {
                $system=$this->gateway->lockSystem($id);
                if ($system===null) throw new SystemException('system_component_not_found');
                if ($system->id!==$id) throw new SystemException('system_component_state_invalid');
                if ($system->lifecycleState!=='ACTIVE') throw new SystemException('system_component_system_inactive');
                $records=$this->gateway->lockComponents($id,$spec->slotKey);
                ComponentTimeline::assertValid($system,$records);
                $slot=ComponentTimeline::slot($records,$spec->slotKey); $current=ComponentTimeline::current($slot);
                if ($expected===null && $current!==null) {
                    if (!$spec->matches($current)) throw new SystemException('system_component_slot_occupied');
                    return new ComponentInstallResult(ComponentCurrentView::of($current),false);
                }
                if ($expected!==null) {
                    if ($current===null) throw new SystemException('system_component_replace_conflict');
                    if ($current->id!==$expected) {
                        $previous=count($slot)>1 ? $slot[count($slot)-2] : null;
                        if (!$spec->matches($current) || $previous===null || $previous->id!==$expected || $previous->removedAt!=$current->installedAt) throw new SystemException('system_component_replace_conflict');
                        return new ComponentReplaceResult(ComponentCurrentView::of($current),false);
                    }
                    if ($spec->typeCode!==$current->typeCode || $spec->slotLabel!==$current->slotLabel) throw new SystemException('system_component_replace_conflict');
                    if ($current->serialNumber!==null && $current->serialNumber===$spec->serialNumber) throw new SystemException('system_component_invalid');
                }
                if ($slot!==[] && ($spec->typeCode!==$slot[0]->typeCode || $spec->slotLabel!==$slot[0]->slotLabel)) throw new SystemException('system_component_state_invalid');
                foreach ($records as $row) if ($row->removedAt===null && $row->slotKey!==$spec->slotKey && $spec->serialNumber!==null && $row->serialNumber===$spec->serialNumber) throw new SystemException('system_component_serial_conflict');
                $at=ComponentTime::seconds($this->clock!==null ? ($this->clock)() : new DateTimeImmutable('now'));
                ComponentTime::assertValid($at);
                $last=$slot===[] ? null : $slot[count($slot)-1];
                if ($at<$system->createdAt || ($last!==null && $at<($last->removedAt ?? $last->installedAt))) throw new SystemException('system_component_state_invalid');
                if ($expected!==null) $this->gateway->closeComponent($id,$expected,$spec->slotKey,$at);
                $record=$this->gateway->insertComponent($id,$spec,$at);
                if ($record->id<1 || $record->systemInternalId!==$id || !$spec->matches($record) || $record->removedAt!==null || $record->installedAt!=$at || $record->createdAt!=$at || $record->updatedAt!=$at) throw new SystemException('system_component_state_invalid');
                $view=ComponentCurrentView::of($record);
                return $expected===null ? new ComponentInstallResult($view,true) : new ComponentReplaceResult($view,true);
            });
        } catch (ComponentStateInvalid) { throw new SystemException('system_component_state_invalid'); }
        catch (SystemException $error) {
            $allowed=['system_component_invalid','system_component_not_found','system_component_system_inactive','system_component_slot_occupied','system_component_replace_conflict','system_component_serial_conflict','system_component_state_invalid','system_component_read_failed'];
            throw new SystemException(in_array($error->machineCode,$allowed,true) ? $error->machineCode : 'system_component_persist_failed');
        } catch (Throwable) { throw new SystemException('system_component_persist_failed'); }
    }
}
