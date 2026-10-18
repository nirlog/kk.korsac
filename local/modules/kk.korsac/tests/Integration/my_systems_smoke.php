<?php

declare(strict_types=1);

// Operator-only read gate; no account HTTP endpoint and no write mode.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

use Bitrix\Main\Loader;
use KK\Korsac\Health\OwnershipSchemaSelfCheck;
use KK\Korsac\Health\PassportSchemaSelfCheck;
use KK\Korsac\Health\SystemComponentDataHealth;
use KK\Korsac\Health\SystemSchemaSelfCheck;
use KK\Korsac\Install\BitrixOwnershipTableGateway;
use KK\Korsac\Install\BitrixPassportTableGateway;
use KK\Korsac\Install\BitrixSystemTableGateway;
use KK\Korsac\MySystems\MySystemsCliInput;
use KK\Korsac\System\SystemException;
use KK\Korsac\System\SystemServiceFactory;

$documentRoot=($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__,5);
$_SERVER['DOCUMENT_ROOT']=$documentRoot;
if (!is_file($documentRoot.'/bitrix/modules/main/include/prolog_before.php')) { fwrite(STDERR,"{\"ok\":false,\"code\":\"bitrix_environment_required\"}\n"); exit(2); }
try {
    require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
    if (!Loader::includeModule('kk.korsac')) throw new SystemException('system_module_required');
    $options=[];
    foreach (array_slice($argv,1) as $argument) {
        if (!preg_match('/\A--(user|limit|offset)=(.*)\z/D',$argument,$match) || isset($options[$match[1]])) throw new SystemException('system_cli_arguments_invalid');
        $options[$match[1]]=$match[2];
    }
    $input=$options===[] ? null : MySystemsCliInput::fromOptions($options);
    $checks=['systems'=>(new SystemSchemaSelfCheck(new BitrixSystemTableGateway()))->run(),'passports'=>(new PassportSchemaSelfCheck(new BitrixPassportTableGateway()))->run(),'ownership'=>(new OwnershipSchemaSelfCheck(new BitrixOwnershipTableGateway()))->run(),'components'=>(new SystemComponentDataHealth())->run()];
    $output=['ok'=>true,'mode'=>'read_only']+$checks;
    foreach ($checks as $check) if (!$check['ok']) $output['ok']=false;
    if ($output['ok'] && $input!==null) $output['page']=SystemServiceFactory::mySystems()->listForUser($input->userId,$input->page)->toArray();
    echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
    exit($output['ok'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR,json_encode(['ok'=>false,'code'=>$error instanceof SystemException ? $error->machineCode : 'my_systems_read_failed']).PHP_EOL); exit(1);
}
