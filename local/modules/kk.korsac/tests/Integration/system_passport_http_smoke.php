<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Install\PassportDeliveryFactory;
use KK\Korsac\Passport\PublicPassportHttpPresenter;
use KK\Korsac\Passport\PublicPassportHttpView;
use KK\Korsac\System\SystemException;
use KK\Korsac\System\SystemServiceFactory;

$documentRoot=($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__,5);
$_SERVER['DOCUMENT_ROOT']=$documentRoot;
if (!is_file($documentRoot.'/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(STDERR,"{\"ok\":false,\"code\":\"bitrix_environment_required\"}\n"); exit(2);
}
try {
    require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
    if (!Loader::includeModule('kk.korsac')) throw new SystemException('system_module_required');
    $options=[];
    foreach (array_slice($argv,1) as $argument) {
        if (!preg_match('/\A--(public-id|site)=(.*)\z/D',$argument,$match) || isset($options[$match[1]])) throw new SystemException('system_cli_arguments_invalid');
        $options[$match[1]]=$match[2];
    }
    $health=PassportDeliveryFactory::create($options['site'] ?? null)->check();
    $result=['ok'=>$health['ok'],'mode'=>'read_only','delivery'=>$health,'expectedResponseHeaders'=>PublicPassportHttpView::headers(),'realHttpRoutingChecked'=>false];
    if (isset($options['public-id'])) {
        $view=(new PublicPassportHttpPresenter(static fn(string $id)=>SystemServiceFactory::publicPassports()->find($id)))->present($options['public-id']);
        $result['page']=['status'=>$view->status,'state'=>$view->state];
        $result['ok']=$result['ok'] && $view->status===200;
    }
    echo json_encode($result,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),PHP_EOL;
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR,json_encode(['ok'=>false,'code'=>$error instanceof SystemException ? $error->machineCode : 'system_passport_http_smoke_failed']).PHP_EOL); exit(1);
}
