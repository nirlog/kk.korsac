<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use KK\Korsac\Cart\BitrixConfigurationSnapshotRepository;
/** Explicit wiring; no container or mutable service registry. */
final class SystemServiceFactory
{
    public static function configuration(): SaleAutomationConfiguration { return new SaleAutomationConfiguration(new BitrixSystemSettingsGateway()); }
    public static function production(): ProductionStatusService { return new ProductionStatusService(new BitrixProductionStatusGateway()); }
    public static function registration(): SaleHandlerRegistration { return new SaleHandlerRegistration(new BitrixSaleHandlerRegistrationGateway()); }
    public static function synchronizer(): OrderSystemSynchronizationService
    {
        $systems=new BitrixSystemRepository(); $snapshots=new BitrixConfigurationSnapshotRepository();
        return new OrderSystemSynchronizationService(self::configuration(),$systems,new SystemCreationService($systems,new SystemIdGenerator(new BitrixSystemSequenceAllocator()),$snapshots),$snapshots,self::production());
    }
    public static function automation(): SaleOrderAutomation { return new SaleOrderAutomation(self::configuration(),new BitrixOrderProjectionAdapter(),self::synchronizer(),new BitrixSaleSynchronizationLogger()); }
    public static function passports(): PassportService { return new PassportService(new BitrixPassportRepository(),new SecurePassportCredentialGenerator()); }
    public static function publicPassports(): PublicPassportService { return new PublicPassportService(new BitrixPassportRepository(),new BitrixSystemRepository(),new BitrixSystemComponentRepository()); }
    public static function ownership(): \KK\Korsac\Ownership\OwnershipService { return new \KK\Korsac\Ownership\OwnershipService(new \KK\Korsac\Ownership\BitrixOwnershipGateway(),new \KK\Korsac\Ownership\BitrixOwnershipUserGateway()); }
    public static function ownershipReads(): \KK\Korsac\Ownership\OwnershipReadService { return new \KK\Korsac\Ownership\OwnershipReadService(new \KK\Korsac\Ownership\BitrixOwnershipGateway()); }

}
