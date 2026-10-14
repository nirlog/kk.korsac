<?php

declare(strict_types=1);

namespace KK\Korsac\Ownership;

use KK\Korsac\System\SystemException;
use Throwable;

final class OwnershipReadService
{
    public function __construct(private readonly OwnershipGatewayInterface $gateway) {}
    public function current(int $id): OwnershipCurrentState
    {
        return $this->read($id,false);
    }
    /** @return list<OwnershipHistoryRecord> */
    public function history(int $id): array { return $this->read($id,true); }
    private function read(int $id,bool $history): mixed
    {
        if (!OwnershipIdentity::valid($id)) throw new SystemException('system_ownership_state_invalid');
        try {
            return $this->gateway->transaction(function() use ($id,$history): mixed {
                $system=$this->gateway->lockSystem($id);
                if ($system===null || $system->systemInternalId!==$id) throw new SystemException('system_ownership_state_invalid');
                $latest=$this->gateway->latestHistory($id);
                OwnershipConsistency::assertCurrent($system,$latest);
                if (!$history) return new OwnershipCurrentState($system->systemId,$system->userId,$latest?->revision,$latest?->createdAt);
                $records=$this->gateway->historyForSystem($id); $revision=0;
                foreach ($records as $record) {
                    $record->assertValid();
                    if ($record->id<1 || $record->systemInternalId!==$id || $record->revision<=$revision) throw new SystemException('system_ownership_state_invalid');
                    $revision=$record->revision;
                }
                if ($revision!==($latest?->revision ?? 0)) throw new SystemException('system_ownership_state_invalid');
                return $records;
            });
        } catch (SystemException $error) { throw new SystemException($error->machineCode==='system_ownership_state_invalid' ? $error->machineCode : 'system_ownership_read_failed'); }
        catch (Throwable) { throw new SystemException('system_ownership_read_failed'); }
    }
}
