<?php

declare(strict_types=1);

namespace KK\Korsac\Repository;

use InvalidArgumentException;
use KK\Korsac\Install\SchemaDefinition;

final class OptionTypeRegistry
{
    public static function has(string $optionType): bool
    {
        return isset(SchemaDefinition::OPTION_TYPES[strtoupper(trim($optionType))]);
    }

    public static function blockName(string $optionType): string
    {
        $type = strtoupper(trim($optionType));
        if (!isset(SchemaDefinition::OPTION_TYPES[$type])) {
            throw new InvalidArgumentException("Unknown option type: {$optionType}");
        }
        return SchemaDefinition::OPTION_TYPES[$type];
    }

    public static function tableName(string $optionType): string
    {
        $entity = SchemaDefinition::entities()[self::blockName($optionType)] ?? null;
        if ($entity === null) {
            throw new InvalidArgumentException("Unknown option type: {$optionType}");
        }
        return $entity['table'];
    }
}
