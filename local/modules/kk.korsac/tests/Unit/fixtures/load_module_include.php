<?php

declare(strict_types=1);

namespace Bitrix\Main {
    final class Loader
    {
        public static array $namespaces = [];
        public static array $modules = [];

        public static function includeModule(string $module): bool
        {
            self::$modules[] = $module;
            return $module === 'catalog';
        }

        public static function registerNamespace(string $namespace, string $path): void
        {
            self::$namespaces[$namespace] = $path;
        }
    }
}

namespace {
    require dirname(__DIR__, 3) . '/include.php';

    return \Bitrix\Main\Loader::$namespaces;
}
