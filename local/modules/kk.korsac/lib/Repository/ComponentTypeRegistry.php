<?php

declare(strict_types=1);

namespace KK\Korsac\Repository;

use InvalidArgumentException;
use KK\Korsac\Install\SchemaDefinition;

final class ComponentTypeRegistry
{
    public static function blockName(string $componentType): string
    {
        $type = strtoupper(trim($componentType));
        if (!isset(SchemaDefinition::COMPONENT_CLASS_TYPES[$type])) {
            throw new InvalidArgumentException("Unknown component type: {$componentType}");
        }
        return SchemaDefinition::COMPONENT_CLASS_TYPES[$type];
    }
}
