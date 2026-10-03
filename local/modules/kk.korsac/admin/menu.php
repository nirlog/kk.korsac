<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    return false;
}

global $USER;
if (!is_object($USER) || (!$USER->IsAdmin() && !$USER->CanDoOperation('edit_php'))) {
    return false;
}

return [
    'parent_menu' => 'global_menu_services',
    'section' => 'kk_korsac',
    'sort' => 500,
    'text' => 'KORSAC',
    'title' => 'KORSAC',
    'items_id' => 'menu_kk_korsac',
    'items' => [[
        'text' => 'Ценообразование',
        'title' => 'Политики ценообразования KORSAC',
        'url' => 'kk_korsac_pricing.php?lang=' . LANGUAGE_ID,
        'more_url' => [],
    ]],
];
