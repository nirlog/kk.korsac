<?php
// kk.korsac:public-passport-delivery:v1

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Passport\PublicPassportHttpView;
use KK\Korsac\Passport\PublicPassportRoute;

// Explicit exception/warning boundaries below provide the safe response; this is defense in depth.
ini_set('display_errors', '0');
$passportStatus=500;
http_response_code($passportStatus);
$passportHeaders=['X-Robots-Tag'=>'noindex, nofollow, noarchive', 'Referrer-Policy'=>'no-referrer', 'Cache-Control'=>'no-store'];
header_register_callback(static function() use (&$passportStatus, &$passportHeaders):void {
    http_response_code($passportStatus);
    foreach ($passportHeaders as $name=>$value) header($name.': '.$value, true);
});
$originalBufferLevel=ob_get_level();
ob_start();
set_error_handler(static function(int $severity):bool {
    if (!(error_reporting() & $severity)) return false;
    if ($severity===E_DEPRECATED || $severity===E_USER_DEPRECATED) return true;
    throw new ErrorException('public_runtime_failed');
});
try {
    $documentRoot=(string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if (!is_file($documentRoot.'/bitrix/modules/main/include/prolog_before.php')) throw new RuntimeException('runtime_unavailable');
    require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
    if (!Loader::includeModule('kk.korsac')) throw new RuntimeException('module_unavailable');
    $passportHeaders=PublicPassportHttpView::headers();
    $requestUri=(string)($_SERVER['REQUEST_URI'] ?? '');
    $readMethod=in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true);
    $redirect=$readMethod ? PublicPassportRoute::redirect($requestUri) : null;
    if ($redirect!==null) {
        $passportStatus=301;
        header('Location: '.$redirect, true, 301); // Relative, validated path only; incoming query is dropped.
        while (ob_get_level()>$originalBufferLevel) ob_end_clean();
        restore_error_handler();
        exit;
    }
    $publicId=$readMethod ? PublicPassportRoute::publicId($requestUri) : '';
    // Resolve/render before the site header, so HTTP status, robots and component CSS are ready.
    ob_start();
    $view=$APPLICATION->IncludeComponent('kk:korsac.passport', '', ['PUBLIC_ID'=>$publicId, 'CACHE_TYPE'=>'N', 'CACHE_TIME'=>0], false, ['HIDE_ICONS'=>'Y']);
    $content=ob_get_clean();
    if (!$view instanceof PublicPassportHttpView) throw new RuntimeException('component_unavailable');
    $passportStatus=$view->status;
    http_response_code($passportStatus);
    require $documentRoot.'/bitrix/header.php';
    echo $content;
    require $documentRoot.'/bitrix/footer.php';
    restore_error_handler();
    while (ob_get_level()>$originalBufferLevel) ob_end_flush();
} catch (Throwable) {
    while (ob_get_level()>$originalBufferLevel) ob_end_clean();
    restore_error_handler();
    $passportStatus=500;
    http_response_code($passportStatus);
    header('Content-Type: text/html; charset=UTF-8', true);
    echo '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow, noarchive"><title>Паспорт системы KORSAC</title></head><body><main><h1>Паспорт системы</h1><p>Паспорт временно недоступен. Попробуйте открыть страницу позже.</p></main></body></html>';
}
