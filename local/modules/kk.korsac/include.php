<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

if (!Loader::includeModule('catalog')) {
    throw new RuntimeException('The catalog module is required by kk.korsac runtime providers.');
}

Loader::registerNamespace('KK\\Korsac', __DIR__ . '/lib');
