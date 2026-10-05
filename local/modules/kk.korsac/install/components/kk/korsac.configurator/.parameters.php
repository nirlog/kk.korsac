<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arComponentParameters = [
    'PARAMETERS' => [
        'IBLOCK_ID' => [
            'PARENT' => 'BASE',
            'NAME' => 'Инфоблок',
            'TYPE' => 'STRING',
        ],
        'PRODUCT_ID' => [
            'PARENT' => 'BASE',
            'NAME' => 'ID товара',
            'TYPE' => 'STRING',
        ],
        'DEBOUNCE_MS' => [
            'PARENT' => 'ADDITIONAL_SETTINGS',
            'NAME' => 'Debounce, мс',
            'TYPE' => 'STRING',
            'DEFAULT' => '150',
        ],
        'DOM_ID' => [
            'PARENT' => 'ADDITIONAL_SETTINGS',
            'NAME' => 'ID DOM-контейнера (необязательно)',
            'TYPE' => 'STRING',
        ],
    ],
];
