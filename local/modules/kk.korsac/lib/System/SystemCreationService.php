<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Closure;
use DateTimeImmutable;
use KK\Korsac\Cart\ConfigurationSnapshotRepositoryInterface;
use Throwable;
final class SystemCreationService
{
    public function __construct(
        private readonly SystemRepositoryInterface $systems,
        private readonly SystemIdGenerator $ids,
        private readonly ConfigurationSnapshotRepositoryInterface $snapshots,
        private readonly ?Closure $clock = null,
    ) {}

    /** One explicitly authorized physical PC unit. No Sale events or UI-label inference. */
    public function create(array $input): SystemRecord
    {
        return $this->createWithResult($input)->system;
    }

    /** Reports actual insert vs idempotent/race winner without changing create() behavior. */
    public function createWithResult(array $input): SystemCreationResult
    {
        $request = SystemCreationRequest::fromArray($input);
        try {
            $existing = $this->systems->findByOrderUnit($request->orderId, $request->orderBasketId, $request->orderUnitIndex);
            if ($existing !== null) return new SystemCreationResult($existing,false);
            if ($request->snapshotKey !== null) {
                $snapshot = $this->snapshots->findByKey($request->snapshotKey);
                if ($snapshot === null) throw new SystemException('system_snapshot_not_found');
                if ($snapshot->siteId !== $request->siteId || ($request->productId !== null && ($snapshot->productId !== $request->productId || $snapshot->iblockId !== $request->iblockId))) {
                    throw new SystemException('system_snapshot_mismatch');
                }
            }
            $now = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now');
            $system = new SystemRecord(0, $this->ids->generate($now), $request, null, 'ACTIVE', $now, $now);
        } catch (SystemException $error) { throw $error; }
        catch (Throwable) { throw new SystemException('system_persist_failed'); }

        try { return new SystemCreationResult($this->systems->create($system),true); }
        catch (Throwable) {
            // A unique-order-unit race is resolved by its authoritative persisted identity.
            // Any other insert failure remains a safe failure, never SQL/driver diagnostics.
            try { $existing = $this->systems->findByOrderUnit($request->orderId, $request->orderBasketId, $request->orderUnitIndex, true); }
            catch (Throwable) { throw new SystemException('system_persist_failed'); }
            if ($existing !== null) return new SystemCreationResult($existing,false);
            throw new SystemException('system_persist_failed');
        }
    }
}
