<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use KK\Korsac\Component\ConfiguratorParameters;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

if (!Loader::includeModule('kk.korsac')) {
    global $USER;
    if (isset($USER) && is_object($USER) && $USER->IsAdmin()) {
        ShowError('Модуль kk.korsac недоступен.');
    }
    return;
}

$parameters = ConfiguratorParameters::normalize($arParams);
$arResult = ['VALID' => $parameters['valid']];
if ($parameters['valid']) {
    Extension::load('kk.korsac.configurator-renderer');
    $arResult['DOM_ID'] = $parameters['domId'];
    $arResult['BOOTSTRAP'] = [
        'domId' => $parameters['domId'],
        'iblockId' => $parameters['iblockId'],
        'productId' => $parameters['productId'],
        'debounceMs' => $parameters['debounceMs'],
    ];
}

$this->IncludeComponentTemplate();
