<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use KK\Korsac\Catalog\ProductConfiguration;

final class ConfigurationSelection
{
    private function __construct(private readonly array $groups) {}

    public static function fromArray(ProductConfiguration $configuration, array $selection): self
    {
        $groups = $configuration->toArray();
        foreach ($selection as $group => $_value) {
            if (!is_string($group) || !array_key_exists($group, $groups)) {
                throw new ConfigurationPricingException(['code' => 'unknown_configuration_group', 'group' => (string)$group]);
            }
        }

        $normalized = [];
        foreach ($groups as $group => $definition) {
            $provided = array_key_exists($group, $selection);
            $value = $provided ? $selection[$group] : ($definition['mode'] === 'single' ? $definition['default'] : []);
            if ($definition['mode'] === 'single') {
                if (is_array($value) || (!is_string($value) && $value !== null)) {
                    throw new ConfigurationPricingException(['code' => 'invalid_single_selection', 'group' => $group]);
                }
                if ($value === null && $definition['default'] !== null) {
                    throw new ConfigurationPricingException(['code' => 'null_not_allowed', 'group' => $group]);
                }
                $allowed = $definition['options'];
                if ($definition['default'] !== null) {
                    $allowed[] = $definition['default'];
                }
                if ($value !== null && !in_array($value, $allowed, true)) {
                    throw new ConfigurationPricingException(['code' => 'option_not_allowed', 'group' => $group, 'xmlId' => $value]);
                }
                $normalized[$group] = $value;
                continue;
            }
            if (!is_array($value) || !array_is_list($value)) {
                throw new ConfigurationPricingException(['code' => 'invalid_multiple_selection', 'group' => $group]);
            }
            $seen = [];
            foreach ($value as $xmlId) {
                if (!is_string($xmlId)) {
                    throw new ConfigurationPricingException(['code' => 'invalid_multiple_selection', 'group' => $group]);
                }
                if (isset($seen[$xmlId])) {
                    throw new ConfigurationPricingException(['code' => 'duplicate_selected_option', 'group' => $group, 'xmlId' => $xmlId]);
                }
                $seen[$xmlId] = true;
                if (!in_array($xmlId, $definition['options'], true)) {
                    throw new ConfigurationPricingException(['code' => 'option_not_allowed', 'group' => $group, 'xmlId' => $xmlId]);
                }
            }
            $normalized[$group] = $value;
        }
        return new self($normalized);
    }

    public function toArray(): array
    {
        return $this->groups;
    }
}
