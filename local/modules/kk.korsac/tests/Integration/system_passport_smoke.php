<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Health\PassportSchemaSelfCheck;
use KK\Korsac\Health\SystemSchemaSelfCheck;
use KK\Korsac\Health\ProductionStatusSchemaSelfCheck;
use KK\Korsac\Install\BitrixPassportTableGateway;
use KK\Korsac\Install\BitrixSystemTableGateway;
use KK\Korsac\Install\BitrixProductionStatusTableGateway;
use KK\Korsac\System\BitrixPassportRepository;
use KK\Korsac\System\BitrixSystemRepository;
use KK\Korsac\System\PassportInternalView;
use KK\Korsac\System\SystemServiceFactory;
use KK\Korsac\System\SystemException;

$documentRoot=($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__,5);
$_SERVER['DOCUMENT_ROOT']=$documentRoot;
if (!is_file($documentRoot.'/bitrix/modules/main/include/prolog_before.php')) { fwrite(STDERR,"{\"ok\":false,\"code\":\"bitrix_environment_required\"}\n"); exit(2); }
require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
$children=[];
try {
    if (!Loader::includeModule('kk.korsac')) throw new SystemException('system_module_required');
    $write=false; $worker=false; $humanId=null; $concurrency=0;
    foreach (array_slice($argv,1) as $arg) {
        if ($arg==='--confirm-write') { $write=true; continue; }
        if ($arg==='--prepare-worker') { $worker=true; continue; }
        if (preg_match('/\A--system=(KS-[0-9]{2}(?:0[1-9]|1[0-2])-[0-9]{4})\z/D',$arg,$m) && $humanId===null) { $humanId=$m[1]; continue; }
        if (preg_match('/\A--concurrency=([2-8])\z/D',$arg,$m) && $concurrency===0) { $concurrency=(int)$m[1]; continue; }
        throw new SystemException('system_cli_arguments_invalid');
    }
    if (($write && $humanId===null) || (($worker || $concurrency>0) && !$write)) throw new SystemException('system_cli_arguments_invalid');
    $schema=(new PassportSchemaSelfCheck(new BitrixPassportTableGateway()))->run();
    $systems=(new SystemSchemaSelfCheck(new BitrixSystemTableGateway()))->run();
    $production=(new ProductionStatusSchemaSelfCheck(new BitrixProductionStatusTableGateway()))->run();
    if (!$schema['ok'] || !$systems['ok'] || !$production['ok']) { echo json_encode(['ok'=>false,'passports'=>$schema,'systems'=>$systems,'production'=>$production],JSON_PRETTY_PRINT),PHP_EOL; exit(1); }
    $output=['ok'=>true,'mode'=>'read_only','passports'=>$schema,'systems'=>$systems,'production'=>$production];
    $repository=new BitrixPassportRepository(); $service=SystemServiceFactory::passports();
    if ($humanId!==null) {
        $system=(new BitrixSystemRepository())->findBySystemId($humanId);
        if ($system===null) throw new SystemException('system_not_found');
        $output['passport']=PassportInternalView::of($humanId,$repository->findBySystem($system->id));
    }
    if ($worker) {
        $result=$service->preparePassport($system->id);
        $verified=$result->created && $result->secretCode!==null && $service->verifySecret($result->passport->publicId,$result->secretCode);
        if ($result->created && !$verified) throw new SystemException('system_passport_smoke_secret_failed');
        // Even the internal worker output contains only a boolean, never a raw credential or hash.
        echo json_encode(['ok'=>true,'publicId'=>$result->passport->publicId,'created'=>$result->created,'secretVerified'=>$verified]),PHP_EOL; exit(0);
    }
    if ($write) {
        if ($repository->findBySystem($system->id)!==null) throw new SystemException('system_passport_smoke_fixture_not_fresh');
        if ($concurrency>0) {
            for ($i=0;$i<$concurrency;++$i) {
                $command=[PHP_BINARY]; if (php_ini_loaded_file()) array_push($command,'-c',php_ini_loaded_file());
                array_push($command,__FILE__,'--confirm-write','--prepare-worker','--system='.$humanId);
                $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if (!is_resource($process)) throw new SystemException('system_passport_smoke_worker_failed');
                fclose($pipes[0]); $children[]=[$process,$pipes];
            }
            $results=[];
            foreach ($children as [$process,$pipes]) {
                $text=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                if (proc_close($process)!==0) throw new SystemException('system_passport_smoke_worker_failed');
                $results[]=json_decode($text,true,512,JSON_THROW_ON_ERROR);
            }
            $children=[];
            if (count(array_unique(array_column($results,'publicId')))!==1 || count(array_filter($results,static fn(array $r):bool=>$r['created']))!==1 || count(array_filter($results,static fn(array $r):bool=>$r['secretVerified']))!==1) throw new SystemException('system_passport_smoke_concurrency_failed');
            $prepared=$service->preparePassport($system->id);
            if ($prepared->created || $prepared->secretCode!==null) throw new SystemException('system_passport_smoke_prepare_failed');
            $secretVerified=true;
        } else {
            $prepared=$service->preparePassport($system->id); $secret=$prepared->secretCode;
            if (!$prepared->created || $secret===null || !$service->verifySecret($prepared->passport->publicId,$secret)) throw new SystemException('system_passport_smoke_secret_failed');
            $stored=$repository->findBySystem($system->id);
            if ($stored===null || $stored->secretCodeHash!==hash('sha256',$secret) || property_exists($stored,'secretCode') || property_exists($stored,'SECRET_CODE')) throw new SystemException('system_passport_smoke_storage_failed');
            $secretVerified=true; unset($secret);
        }
        $record=$prepared->passport; $repeat=$service->preparePassport($system->id);
        if ($repeat->created || $repeat->secretCode!==null || $repeat->passport->publicId!==$record->publicId || $repeat->passport->preparedAt!=$record->preparedAt || $repeat->passport->updatedAt!=$record->updatedAt) throw new SystemException('system_passport_smoke_prepare_failed');
        try { SystemServiceFactory::publicPassports()->find($record->publicId); throw new SystemException('system_passport_smoke_visibility_failed'); }
        catch (SystemException $error) { if ($error->machineCode!=='system_passport_not_found') throw $error; }
        $active=$service->activatePassport($system->id);
        if ($active->state!=='ACTIVE' || $active->transferredAt===null || $active->warrantyStartedAt!=$active->transferredAt || $active->publicId!==$record->publicId || $active->secretCodeHash!==$record->secretCodeHash || $active->preparedAt!=$record->preparedAt) throw new SystemException('system_passport_smoke_activation_failed');
        $public=SystemServiceFactory::publicPassports()->find($active->publicId)->toArray();
        if (array_keys($public)!==['publicId','systemId','systemType','modelName','transferredAt','warrantyStartedAt','components']) throw new SystemException('system_passport_smoke_privacy_failed');
        foreach ($public['components'] as $component) if (array_keys($component)!==['typeCode','slotLabel','name']) throw new SystemException('system_passport_smoke_privacy_failed');
        $again=$service->activatePassport($system->id);
        if ($again->transferredAt!=$active->transferredAt || $again->updatedAt!=$active->updatedAt) throw new SystemException('system_passport_smoke_activation_failed');
        $count=(int)\KK\Korsac\System\PassportTable::getCount(['=SYSTEM_INTERNAL_ID'=>$system->id]);
        if ($count!==1) throw new SystemException('system_passport_smoke_concurrency_failed');
        $output=['ok'=>true,'mode'=>'controlled_write','secretVerified'=>$secretVerified,'passport'=>PassportInternalView::of($humanId,$again),'public'=>$public,'passportRows'=>$count,'concurrentPreparations'=>$concurrency];
    }
    echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    foreach ($children as [$process,$pipes]) if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    fwrite(STDERR,json_encode(['ok'=>false,'code'=>$error instanceof SystemException ? $error->machineCode : 'system_passport_smoke_failed']).PHP_EOL); exit(1);
}
