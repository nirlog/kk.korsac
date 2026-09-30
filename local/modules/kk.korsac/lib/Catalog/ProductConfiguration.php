<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use JsonSerializable;

final class ProductConfiguration implements JsonSerializable
{
    private function __construct(private readonly array $groups) {}

    /**
     * @param array<string,list<string>> $valuesByProperty
     * @param callable(string,string):?array $optionResolver
     */
    public static function fromPropertyValues(array $valuesByProperty, callable $optionResolver): self
    {
        $result = [];
        foreach (CatalogPropertySchema::groups() as $group => $definition) {
            if ($definition['mode'] === CatalogPropertySchema::MODE_MULTIPLE) {
                $code = "KK_{$group}_MULTI_OPTIONS";
                $options = self::validateList($group, $code, $valuesByProperty[$code] ?? [], $optionResolver);
                $result[$group] = ['mode' => 'multiple', 'options' => $options];
                continue;
            }
            $defaultCode = "KK_{$group}_DEFAULT";
            $optionsCode = "KK_{$group}_OPTIONS";
            $defaults = array_values(array_filter($valuesByProperty[$defaultCode] ?? [], static fn(string $value): bool => $value !== ''));
            if (count($defaults) > 1) {
                throw new ProductConfigurationException(['code' => 'multiple_defaults', 'group' => $group, 'property' => $defaultCode]);
            }
            $default = $defaults[0] ?? null;
            if ($default !== null) {
                self::validateReference($group, $defaultCode, $default, $optionResolver);
            }
            $options = self::validateList($group, $optionsCode, $valuesByProperty[$optionsCode] ?? [], $optionResolver);
            if ($default !== null && in_array($default, $options, true)) {
                throw new ProductConfigurationException(['code' => 'default_duplicated_in_options', 'group' => $group, 'xmlId' => $default]);
            }
            $result[$group] = ['mode' => 'single', 'default' => $default, 'options' => $options];
        }
        return new self($result);
    }

    public function toArray(): array
    {
        return $this->groups;
    }

    public function jsonSerialize(): array
    {
        return $this->groups;
    }

    private static function validateList(string $group, string $property, array $values, callable $resolver): array
    {
        $seen = [];
        foreach ($values as $xmlId) {
            if (isset($seen[$xmlId])) {
                throw new ProductConfigurationException(['code' => 'duplicate_option', 'group' => $group, 'xmlId' => $xmlId]);
            }
            $seen[$xmlId] = true;
            self::validateReference($group, $property, $xmlId, $resolver);
        }
        return array_values($values);
    }

    private static function validateReference(string $group, string $property, string $xmlId, callable $resolver): void
    {
        $row = $resolver($group, $xmlId);
        if ($row === null) {
            throw new ProductConfigurationException(['code' => 'missing_option', 'group' => $group, 'property' => $property, 'xmlId' => $xmlId]);
        }
        if (!in_array($row['UF_ACTIVE'] ?? null, [1, '1', true, 'Y'], true)) {
            throw new ProductConfigurationException(['code' => 'inactive_option', 'group' => $group, 'xmlId' => $xmlId]);
        }
    }
}
