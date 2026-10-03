<?php

declare(strict_types=1);

namespace KK\Korsac\Install\Migration;

use KK\Korsac\Exception\SchemaMismatchException;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\SchemaComparator;
use KK\Korsac\Install\SchemaDefinition;
use KK\Korsac\Install\SchemaGatewayInterface;
use RuntimeException;

final class OptionImage implements MigrationInterface
{
    public function __construct(private readonly SchemaGatewayInterface $gateway) {}

    public function id(): string { return '2026_10_03_005_option_image'; }

    public function up(): void
    {
        foreach (SchemaDefinition::entities() as $entity) {
            $block = $this->gateway->getBlock($entity['name']);
            if ($block === null) {
                throw new RuntimeException("Cannot add {$entity['name']}.UF_IMAGE: HL block is missing");
            }
            $expected = $entity['fields']['UF_IMAGE'];
            $actual = $this->gateway->getFields((int)$block['ID'])['UF_IMAGE'] ?? null;
            if ($actual === null) {
                $this->gateway->createField((int)$block['ID'], $expected);
            } elseif (!SchemaComparator::fieldIsCompatible($actual, $expected)) {
                throw new SchemaMismatchException("Incompatible field {$entity['name']}.UF_IMAGE");
            }
        }
    }
}
