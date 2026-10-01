<?php
declare(strict_types=1);
namespace KK\Korsac\Install\Migration;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\SnapshotTableGatewayInterface;
final class ConfigurationSnapshot implements MigrationInterface
{
    public const ID='2026_10_02_004_configuration_snapshot';
    public const TABLE='b_kk_korsac_config_snapshot';
    public const INDEXES=[
        'ux_kk_korsac_snapshot_key'=>[['SNAPSHOT_KEY'],true],
        'ix_kk_korsac_snapshot_product'=>[['PRODUCT_ID'],false],
        'ix_kk_korsac_snapshot_created'=>[['CREATED_AT'],false],
    ];
    public function __construct(private readonly SnapshotTableGatewayInterface $gateway) {}
    public function id(): string { return self::ID; }
    public function up(): void
    {
        if (!$this->gateway->tableExists(self::TABLE)) $this->gateway->createTable(self::TABLE);
        foreach (self::INDEXES as $name=>[$columns,$unique]) if (!$this->gateway->indexExists(self::TABLE,$name)) $this->gateway->createIndex(self::TABLE,$name,$columns,$unique);
    }
}
