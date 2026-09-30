<?php

declare(strict_types=1);

namespace KK\Korsac\Install\Migration;

use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\SchemaGatewayInterface;
use KK\Korsac\Install\SchemaInstaller;
use RuntimeException;

final class SimplifyHlSchema implements MigrationInterface
{
    public const LEGACY_BLOCKS = [
        'KorsacCpuClass', 'KorsacGpuClass', 'KorsacMotherboardClass', 'KorsacRamClass',
        'KorsacSsdClass', 'KorsacPsuClass', 'KorsacCoolerClass', 'KorsacCaseClass',
        'KorsacServiceClass', 'KorsacPhysicalSku', 'KorsacSupplierOffer', 'KorsacValidatedBuild',
    ];

    public function __construct(
        private readonly SchemaGatewayInterface $gateway,
        private readonly SchemaInstaller $installer,
    ) {}

    public function id(): string { return '2026_09_30_002_simplify_hl_schema'; }

    public function up(): void
    {
        $occupied = [];
        foreach (self::LEGACY_BLOCKS as $blockName) {
            $count = $this->gateway->countRows($blockName);
            if ($count > 0) {
                $occupied[$blockName] = $count;
            }
        }
        if ($occupied !== []) {
            $lines = ['KORSAC v0.2 destructive migration blocked:'];
            foreach ($occupied as $blockName => $count) {
                $lines[] = "{$blockName} contains {$count} rows";
            }
            throw new RuntimeException(implode("\n", $lines));
        }
        foreach (self::LEGACY_BLOCKS as $blockName) {
            $this->gateway->deleteBlock($blockName);
        }
        $this->installer->install();
    }
}
