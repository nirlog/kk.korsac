<?php

declare(strict_types=1);

namespace KK\Korsac\Install\Migration;

use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\SchemaDefinition;
use KK\Korsac\Install\SchemaGatewayInterface;
use RuntimeException;

final class PricePrecision implements MigrationInterface
{
    public function __construct(private readonly SchemaGatewayInterface $gateway) {}

    public function id(): string { return '2026_09_30_003_price_precision'; }

    public function up(): void
    {
        foreach (SchemaDefinition::entities() as $entity) {
            $block = $this->gateway->getBlock($entity['name']);
            if ($block === null) {
                throw new RuntimeException("Cannot update {$entity['name']}.UF_PRICE: HL block is missing");
            }
            $field = $this->gateway->getFields((int)$block['ID'])['UF_PRICE'] ?? null;
            if ($field === null || !isset($field['ID'])) {
                throw new RuntimeException("Cannot update {$entity['name']}.UF_PRICE: user field is missing");
            }
            $settings = $field['SETTINGS'] ?? [];
            if (is_string($settings)) {
                $settings = unserialize($settings, ['allowed_classes' => false]);
            }
            if (!is_array($settings)) {
                throw new RuntimeException("Cannot update {$entity['name']}.UF_PRICE: invalid SETTINGS");
            }
            if (array_key_exists('PRECISION', $settings) && (int)$settings['PRECISION'] === 2) {
                continue;
            }
            $settings['PRECISION'] = 2;
            $this->gateway->updateFieldSettings((int)$field['ID'], $settings);
        }
    }
}
