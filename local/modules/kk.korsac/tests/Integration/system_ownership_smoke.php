<?php

declare(strict_types=1);

// Private test orchestration is CLI-only, including the stdin credential worker.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

use Bitrix\Main\Loader;
use KK\Korsac\Health\OwnershipSchemaSelfCheck;
use KK\Korsac\Health\SystemSchemaSelfCheck;
use KK\Korsac\Health\PassportSchemaSelfCheck;
use KK\Korsac\Install\BitrixOwnershipTableGateway;
use KK\Korsac\Install\BitrixSystemTableGateway;
use KK\Korsac\Install\BitrixPassportTableGateway;
use KK\Korsac\Ownership\BitrixOwnershipUserGateway;
use KK\Korsac\System\BitrixPassportRepository;
use KK\Korsac\System\BitrixSystemRepository;
use KK\Korsac\System\PassportCredentialShape;
use KK\Korsac\System\SystemException;
use KK\Korsac\System\SystemServiceFactory;

$documentRoot=($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__,5);
$_SERVER['DOCUMENT_ROOT']=$documentRoot;
if (!is_file($documentRoot.'/bitrix/modules/main/include/prolog_before.php')) { fwrite(STDERR,"{\"ok\":false,\"code\":\"bitrix_environment_required\"}\n"); exit(2); }
$children=[];
try {
    require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
    if (!Loader::includeModule('kk.korsac')) throw new SystemException('system_module_required');
    $write=false; $worker=false; $humanId=null; $userId=null; $publicId=null; $concurrency=0;
    foreach (array_slice($argv,1) as $arg) {
        if ($arg==='--confirm-write' && !$write) { $write=true; continue; }
        if ($arg==='--claim-worker' && !$worker) { $worker=true; continue; }
        if (preg_match('/\A--system=(KS-[0-9]{2}(?:0[1-9]|1[0-2])-[0-9]{4})\z/D',$arg,$m) && $humanId===null) { $humanId=$m[1]; continue; }
        if (preg_match('/\A--user=([1-9][0-9]*)\z/D',$arg,$m) && $userId===null && (int)$m[1]<=2147483647) { $userId=(int)$m[1]; continue; }
        if (preg_match('/\A--public-id=([a-f0-9]{32})\z/D',$arg,$m) && $publicId===null) { $publicId=$m[1]; continue; }
        if (preg_match('/\A--concurrency=([2-8])\z/D',$arg,$m) && $concurrency===0) { $concurrency=(int)$m[1]; continue; }
        throw new SystemException('system_cli_arguments_invalid');
    }
    if (($write && ($humanId===null || $userId===null)) || (!$write && ($worker || $userId!==null || $concurrency>0)) || ($worker!==($publicId!==null)) || ($worker && $concurrency>0)) throw new SystemException('system_cli_arguments_invalid');
    $ownership=(new OwnershipSchemaSelfCheck(new BitrixOwnershipTableGateway()))->run();
    $systems=(new SystemSchemaSelfCheck(new BitrixSystemTableGateway()))->run();
    $passports=(new PassportSchemaSelfCheck(new BitrixPassportTableGateway()))->run();
    if (!$ownership['ok'] || !$systems['ok'] || !$passports['ok']) { echo json_encode(['ok'=>false,'ownership'=>$ownership,'systems'=>$systems,'passports'=>$passports],JSON_PRETTY_PRINT),PHP_EOL; exit(1); }
    $output=['ok'=>true,'mode'=>'read_only','ownership'=>$ownership,'systems'=>$systems,'passports'=>$passports];
    $systemsRepo=new BitrixSystemRepository(); $passportRepo=new BitrixPassportRepository(); $reads=SystemServiceFactory::ownershipReads();
    if ($humanId!==null) {
        $system=$systemsRepo->findBySystemId($humanId);
        if ($system===null) throw new SystemException('system_not_found');
        $output['current']=$reads->current($system->id)->toArray();
    }
    if ($worker) {
        // Raw credential is supplied only over an anonymous stdin pipe, never argv/env/stdout/files.
        $secret=stream_get_contents(STDIN,33);
        if (!is_string($secret) || !PassportCredentialShape::token($secret)) throw new SystemException('system_ownership_credentials_invalid');
        $result=SystemServiceFactory::ownership()->claim($publicId,$secret,$userId);
        if ($result->systemId!==$humanId) throw new SystemException('system_ownership_smoke_worker_failed');
        unset($secret);
        echo json_encode(['ok'=>true,'systemId'=>$result->systemId,'userId'=>$result->userId,'claimed'=>$result->claimed,'revision'=>$result->revision]),PHP_EOL; exit(0);
    }
    if ($write) {
        $current=$reads->current($system->id);
        if ($system->lifecycleState!=='ACTIVE' || $current->userId!==null || $current->revision!==null || $passportRepo->findBySystem($system->id)!==null) throw new SystemException('system_ownership_smoke_fixture_not_fresh');
        if (!(new BitrixOwnershipUserGateway())->isActiveUser($userId)) throw new SystemException('system_ownership_user_invalid');
        $preparation=SystemServiceFactory::passports()->preparePassport($system->id); $secret=$preparation->secretCode;
        if (!$preparation->created || $secret===null) throw new SystemException('system_ownership_smoke_fixture_not_fresh');
        $active=SystemServiceFactory::passports()->activatePassport($system->id);
        $service=SystemServiceFactory::ownership();
        if ($concurrency>0) {
            for ($i=0;$i<$concurrency;++$i) {
                $command=[PHP_BINARY]; if (php_ini_loaded_file()) array_push($command,'-c',php_ini_loaded_file());
                array_push($command,__FILE__,'--claim-worker','--confirm-write','--system='.$humanId,'--user='.$userId,'--public-id='.$active->publicId);
                $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if (!is_resource($process)) throw new SystemException('system_ownership_smoke_worker_failed');
                $children[]=[$process,$pipes];
                if (fwrite($pipes[0],$secret)!==strlen($secret)) throw new SystemException('system_ownership_smoke_worker_failed');
                fclose($pipes[0]);
            }
            $results=[];
            foreach ($children as [$process,$pipes]) {
                $text=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                if (proc_close($process)!==0) throw new SystemException('system_ownership_smoke_worker_failed');
                $result=json_decode($text,true,512,JSON_THROW_ON_ERROR);
                if (($result['ok'] ?? false)!==true || ($result['revision'] ?? null)!==1 || ($result['systemId'] ?? null)!==$humanId || ($result['userId'] ?? null)!==$userId) throw new SystemException('system_ownership_smoke_concurrency_failed');
                $results[]=$result;
            }
            $children=[];
            if (count(array_filter($results,static fn(array $result):bool=>$result['claimed']))!==1) throw new SystemException('system_ownership_smoke_concurrency_failed');
        } else {
            $first=$service->claim($active->publicId,$secret,$userId);
            if (!$first->claimed || $first->revision!==1 || $first->userId!==$userId) throw new SystemException('system_ownership_smoke_claim_failed');
        }
        $after=$systemsRepo->findById($system->id); $history=$reads->history($system->id);
        if ($after===null || $after->userId!==$userId || count($history)!==1) throw new SystemException('system_ownership_smoke_claim_failed');
        $event=$history[0];
        if ($event->revision!==1 || $event->fromUserId!==null || $event->toUserId!==$userId || $event->actorUserId!==$userId || $event->eventCode!=='CLAIMED' || $event->sourceCode!=='SECRET_CLAIM' || $event->createdAt!=$after->updatedAt) throw new SystemException('system_ownership_smoke_claim_failed');
        $again=$service->claim($active->publicId,$secret,$userId); unset($secret,$preparation);
        $repeat=$systemsRepo->findById($system->id);
        if ($again->claimed || $again->revision!==1 || $again->claimedAt!=$event->createdAt || $repeat===null || $repeat->updatedAt!=$after->updatedAt || count($reads->history($system->id))!==1) throw new SystemException('system_ownership_smoke_repeat_failed');
        if ($passportRepo->findBySystem($system->id)!=$active) throw new SystemException('system_ownership_smoke_passport_changed');
        $public=SystemServiceFactory::publicPassports()->find($active->publicId)->toArray();
        if (array_keys($public)!==['publicId','systemId','systemType','modelName','transferredAt','warrantyStartedAt','components']) throw new SystemException('system_ownership_smoke_privacy_failed');
        foreach ($public['components'] as $component) if (array_keys($component)!==['typeCode','slotLabel','name']) throw new SystemException('system_ownership_smoke_privacy_failed');
        $output=['ok'=>true,'mode'=>'controlled_write','claimEstablished'=>true,'repeatClaimed'=>false,'current'=>$reads->current($system->id)->toArray(),'historyRows'=>1,'revision'=>1,'concurrentClaims'=>$concurrency,'publicOwnershipBlind'=>true];
    }
    echo json_encode($output,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    foreach ($children as [$process,$pipes]) if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    fwrite(STDERR,json_encode(['ok'=>false,'code'=>$error instanceof SystemException ? $error->machineCode : 'system_ownership_smoke_failed']).PHP_EOL); exit(1);
}
