<?php

declare(strict_types=1);

namespace KK\Korsac\Cart;

final class BasketPropertyProjector
{
    public function project(ConfigurationSnapshot $snapshot): array
    {
        $properties = [
            $this->property('KORSAC_CONFIGURED', 'KORSAC configured product', 'Y'),
            $this->property('KORSAC_SNAPSHOT_KEY', 'KORSAC snapshot key', $snapshot->key),
            $this->property('KORSAC_SNAPSHOT_HASH', 'KORSAC snapshot hash', $snapshot->hash),
            $this->property('KORSAC_SNAPSHOT_VERSION', 'KORSAC snapshot version', (string)ConfigurationSnapshot::SCHEMA_VERSION),
        ];
        foreach ($snapshot->display as $group => $definition) {
            foreach ($definition['items'] as $index => $item) {
                $suffix = $definition['mode'] === 'multiple' ? '_' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT) : '';
                $properties[] = $this->property('KORSAC_' . $group . $suffix, $group, $item['name']);
            }
        }
        return $properties;
    }

    private function property(string $code, string $name, string $value): array
    {
        return ['CODE' => $code, 'NAME' => $name, 'VALUE' => $value, 'SORT' => 100];
    }
}
