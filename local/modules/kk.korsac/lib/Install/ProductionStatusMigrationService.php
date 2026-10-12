<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
use KK\Korsac\Install\Migration\SystemSaleAutomation;
use KK\Korsac\System\SaleHandlerRegistration;
final class ProductionStatusMigrationService
{
    public function __construct(private readonly MigrationStoreInterface $store,private readonly SystemTableGatewayInterface $gateway,private readonly SaleHandlerRegistration $registration) {}
    public function migrate(): array
    {
        $applied=(new MigrationRunner($this->store))->run([new SystemSaleAutomation($this->gateway)]);
        // Registration must also be repairable when migration 007 is already recorded.
        $this->registration->ensure();
        return $applied;
    }
}
