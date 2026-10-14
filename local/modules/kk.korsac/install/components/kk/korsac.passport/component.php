<?php
// kk.korsac:public-passport-delivery:v1

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Passport\PublicPassportHttpPresenter;
use KK\Korsac\Passport\PublicPassportHttpView;
use KK\Korsac\System\SystemServiceFactory;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true) die();

$bufferLevel=ob_get_level();
ob_start();
try {
    if (!Loader::includeModule('kk.korsac')) throw new RuntimeException('module_unavailable');
    $view=(new PublicPassportHttpPresenter(static fn(string $id)=>SystemServiceFactory::publicPassports()->find($id)))->present($arParams['PUBLIC_ID'] ?? '');
    http_response_code($view->status);
    if (!headers_sent()) foreach (PublicPassportHttpView::headers() as $name=>$value) header($name.': '.$value, true);
    $APPLICATION->SetTitle('Паспорт системы KORSAC');
    $APPLICATION->SetPageProperty('robots', 'noindex, nofollow, noarchive');
    $arResult=['VIEW'=>$view];
    // No StartResultCache: current physical component truth is read on every request.
    $this->IncludeComponentTemplate();
    ob_end_flush();
    return $view;
} catch (Throwable) {
    while (ob_get_level()>$bufferLevel) ob_end_clean();
    http_response_code(500);
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('Referrer-Policy: no-referrer', true);
        header('Cache-Control: no-store', true);
    }
    echo '<main class="kk-korsac-passport"><h1>Паспорт системы</h1><p>Паспорт временно недоступен. Попробуйте открыть страницу позже.</p></main>';
    return class_exists(PublicPassportHttpView::class, false) ? PublicPassportHttpView::error() : null;
}
