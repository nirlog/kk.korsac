<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Closure;
use DateTimeImmutable;
use Throwable;
final class ProductionStatusService
{
    public function __construct(private readonly ProductionStatusGatewayInterface $gateway, private readonly ?Closure $clock = null) {}
    public function setStatus(int $systemInternalId, string $statusCode, string $sourceCode, ?int $actorUserId = null): ProductionStatusState
    {
        return $this->change($systemInternalId,$statusCode,$sourceCode,$actorUserId,false);
    }
    /** Repair only missing initial status; a later workflow transition is never reset by synchronization. */
    public function ensureInitial(int $systemInternalId): ProductionStatusState
    {
        return $this->change($systemInternalId,'WAREHOUSE','SALE_AUTOMATION',null,true);
    }
    private function change(int $id,string $status,string $source,?int $actor,bool $initialOnly): ProductionStatusState
    {
        if ($id<1 || $id>2147483647 || ($actor!==null && ($actor<1 || $actor>2147483647))) throw new SystemException('system_production_source_invalid');
        ProductionStatusRegistry::validate($status);
        if (!in_array($source,['SALE_AUTOMATION','MANUAL'],true)) throw new SystemException('system_production_source_invalid');
        try {
            return $this->gateway->transaction(function () use ($id,$status,$source,$actor,$initialOnly): ProductionStatusState {
                $current=$this->gateway->lockCurrent($id); // Verifies/locks the System itself as well.
                if ($current!==null) {
                    ProductionStatusRegistry::validate($current->statusCode);
                    if ($current->revision<1 || $current->systemInternalId!==$id) throw new SystemException('system_production_state_invalid');
                }
                if ($current!==null && ($initialOnly || $current->statusCode===$status)) return $current;
                if ($current!==null && ($current->revision<1 || $current->revision>=2147483647)) throw new SystemException('system_production_revision_exhausted');
                $now=$this->clock!==null ? ($this->clock)() : new DateTimeImmutable('now');
                $state=new ProductionStatusState($id,$status,($current?->revision ?? 0)+1,$now);
                $this->gateway->writeCurrent($state,$current===null);
                $this->gateway->appendHistory(new ProductionStatusHistory($id,$state->revision,$current?->statusCode,$status,$source,$actor,$now));
                return $state;
            });
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_production_persist_failed'); }
    }
}
