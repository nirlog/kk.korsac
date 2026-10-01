<?php
declare(strict_types=1);
namespace KK\Korsac\Install;
use KK\Korsac\Install\Migration\ConfigurationSnapshot;
final class SnapshotMigrationService
{
    public function __construct(private readonly MigrationStoreInterface $store,private readonly SnapshotTableGatewayInterface $gateway) {}
    public function migrate(): array { return (new MigrationRunner($this->store))->run([new ConfigurationSnapshot($this->gateway)]); }
}
