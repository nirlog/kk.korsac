<?php

declare(strict_types=1);

namespace KK\Korsac\MySystems;

use KK\Korsac\Component\ComponentStateInvalid;
use KK\Korsac\Component\ComponentSystemState;
use KK\Korsac\Component\ComponentTime;
use KK\Korsac\Component\ComponentTimeline;
use KK\Korsac\Ownership\OwnershipConsistency;
use KK\Korsac\Ownership\OwnershipSystemState;
use KK\Korsac\System\PassportPath;
use KK\Korsac\System\SystemException;
use Throwable;

final class MySystemsService
{
    public function __construct(private readonly MySystemsGatewayInterface $gateway) {}
    /** Trusted application caller only. Future HTTP MUST derive userId from the server session/CurrentUser. */
    public function listForUser(#[\SensitiveParameter] int $userId,MySystemsPageRequest $page=new MySystemsPageRequest()): MySystemsPage
    {
        if ($userId<1 || $userId>2147483647) throw new SystemException('my_systems_user_invalid');
        try {
            $records=$this->gateway->findForUser($userId,$page);
            if (count($records)>$page->limit+1) throw new SystemException('my_systems_state_invalid');
            $items=[]; $ids=[]; $humanIds=[]; $previous=null;
            foreach ($records as $record) {
                if (!$record instanceof MySystemReadRecord || $record->userId!==$userId || isset($ids[$record->internalId]) || isset($humanIds[$record->systemId])) throw new SystemException('my_systems_state_invalid');
                if ($previous!==null && ($record->createdAt>$previous->createdAt || ($record->createdAt==$previous->createdAt && strcmp($record->systemId,$previous->systemId)>=0))) throw new SystemException('my_systems_state_invalid');
                $ids[$record->internalId]=true; $humanIds[$record->systemId]=true;
                $items[]=$this->project($record); $previous=$record;
            }
            return new MySystemsPage(array_slice($items,0,$page->limit),count($items)>$page->limit);
        } catch (ComponentStateInvalid) { throw new SystemException('my_systems_state_invalid'); }
        catch (SystemException $error) {
            $state=['my_systems_state_invalid','system_component_invalid','system_component_state_invalid','system_passport_state_invalid','system_passport_transfer_time_invalid','system_ownership_state_invalid'];
            throw new SystemException(in_array($error->machineCode,$state,true) ? 'my_systems_state_invalid' : 'my_systems_read_failed');
        } catch (Throwable) { throw new SystemException('my_systems_read_failed'); }
    }
    private function project(#[\SensitiveParameter] MySystemReadRecord $record): MySystemView
    {
        $owner=new OwnershipSystemState($record->internalId,$record->systemId,$record->userId,$record->lifecycleState,$record->createdAt,$record->updatedAt);
        OwnershipConsistency::assertCurrent($owner,$record->latestOwnership);
        ComponentTime::assertValid($record->createdAt); ComponentTime::assertValid($record->updatedAt);
        if (!in_array($record->systemType,['MODEL','CUSTOM'],true) || !mb_check_encoding($record->modelName,'UTF-8') || trim($record->modelName)==='' || mb_strlen($record->modelName,'UTF-8')>255 || preg_match('/[\x00-\x1f\x7f]/u',$record->modelName)) throw new SystemException('my_systems_state_invalid');
        $passport=$record->passport; $transfer=null; $warranty=null; $path=null;
        if ($passport!==null) {
            $passport->assertValid();
            if ($passport->systemInternalId!==$record->internalId) throw new SystemException('my_systems_state_invalid');
            foreach ([$passport->preparedAt,$passport->updatedAt,$passport->transferredAt,$passport->warrantyStartedAt] as $at) if ($at!==null) ComponentTime::assertValid($at);
            if ($passport->state==='ACTIVE') { $transfer=$passport->transferredAt->format(DATE_ATOM); $warranty=$passport->warrantyStartedAt->format(DATE_ATOM); $path=PassportPath::build($passport->publicId); }
        }
        $parent=new ComponentSystemState($record->internalId,$record->lifecycleState,$record->createdAt);
        ComponentTimeline::assertValid($parent,$record->components);
        $components=[];
        foreach (ComponentTimeline::ordered($record->components) as $component) if ($component->removedAt===null) $components[]=new MySystemComponentView($component->typeCode,$component->slotLabel,$component->name);
        return new MySystemView($record->systemId,$record->systemType,$record->modelName,$record->lifecycleState,$transfer,$warranty,$path,$components);
    }
}
