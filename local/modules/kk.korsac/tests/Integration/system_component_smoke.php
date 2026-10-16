<?php

declare(strict_types=1);

// Private test orchestration, including workers, is never an HTTP mutation endpoint.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

use Bitrix\Main\Loader;
use KK\Korsac\Component\ComponentCurrentView;
use KK\Korsac\Component\ComponentSpec;
use KK\Korsac\Health\PassportSchemaSelfCheck;
use KK\Korsac\Health\SystemComponentDataHealth;
use KK\Korsac\Health\SystemSchemaSelfCheck;
use KK\Korsac\Install\BitrixPassportTableGateway;
use KK\Korsac\Install\BitrixSystemTableGateway;
use KK\Korsac\System\BitrixPassportRepository;
use KK\Korsac\System\BitrixSystemRepository;
use KK\Korsac\System\SystemException;
use KK\Korsac\System\SystemServiceFactory;

$documentRoot=($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__,5);
$_SERVER['DOCUMENT_ROOT']=$documentRoot;
if (!is_file($documentRoot.'/bitrix/modules/main/include/prolog_before.php')) { fwrite(STDERR,"{\"ok\":false,\"code\":\"bitrix_environment_required\"}\n"); exit(2); }
$children=[];
try {
    require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
    if (!Loader::includeModule('kk.korsac')) throw new SystemException('system_module_required');
    $write=false; $worker=false; $humanId=null; $slot='SSD_1'; $slotSet=false; $concurrency=0;
    foreach (array_slice($argv,1) as $argument) {
        if ($argument==='--confirm-write' && !$write) { $write=true; continue; }
        if ($argument==='--component-worker' && !$worker) { $worker=true; continue; }
        if (preg_match('/\A--system=(KS-[0-9]{2}(?:0[1-9]|1[0-2])-[0-9]{4})\z/D',$argument,$m) && $humanId===null) { $humanId=$m[1]; continue; }
        if (preg_match('/\A--slot=([A-Z][A-Z0-9_]{0,63})\z/D',$argument,$m) && !$slotSet) { $slot=$m[1]; $slotSet=true; continue; }
        if (preg_match('/\A--concurrency=([2-8])\z/D',$argument,$m) && $concurrency===0) { $concurrency=(int)$m[1]; continue; }
        throw new SystemException('system_cli_arguments_invalid');
    }
    if (($write && $humanId===null) || (!$write && ($worker || $concurrency>0)) || ($worker && $concurrency>0)) throw new SystemException('system_cli_arguments_invalid');
    $systems=new BitrixSystemRepository(); $reads=SystemServiceFactory::componentReads(); $service=SystemServiceFactory::components();
    $system=$humanId===null ? null : $systems->findBySystemId($humanId);
    if ($humanId!==null && $system===null) throw new SystemException('system_not_found');
    if ($worker) {
        // Test-only IPC. Workers always use NULL serial; nothing private goes in argv/env/files/output.
        $payload=json_decode(stream_get_contents(STDIN,4096),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !isset($payload['mode'],$payload['name']) || !in_array($payload['mode'],['install','replace'],true) || !is_string($payload['name']) || !preg_match('/\AKORSAC smoke [AB] [a-f0-9]{16}\z/D',$payload['name'])) throw new SystemException('system_cli_arguments_invalid');
        $spec=new ComponentSpec('SSD',$slot,'Component smoke slot',$payload['name'],null);
        if ($payload['mode']==='install') { $result=$service->install($system->id,$spec); $changed=$result->created; }
        else {
            if (!isset($payload['expected']) || !is_int($payload['expected'])) throw new SystemException('system_cli_arguments_invalid');
            $result=$service->replace($system->id,$payload['expected'],$spec); $changed=$result->replaced;
        }
        echo json_encode(['ok'=>true,'changed'=>$changed,'componentId'=>$result->component->componentId]),PHP_EOL; exit(0);
    }
    $checks=['systems'=>(new SystemSchemaSelfCheck(new BitrixSystemTableGateway()))->run(),'components'=>(new SystemComponentDataHealth())->run(),'passports'=>(new PassportSchemaSelfCheck(new BitrixPassportTableGateway()))->run()];
    $output=['ok'=>true,'mode'=>'read_only']+$checks;
    foreach ($checks as $check) if (!$check['ok']) { $output['ok']=false; echo json_encode($output,JSON_PRETTY_PRINT),PHP_EOL; exit(1); }
    $viewArray=static fn(ComponentCurrentView $view):array=>$view->toArray();
    $inSlot=static fn(array $views):array=>array_values(array_filter($views,static fn(ComponentCurrentView $view):bool=>$view->slotKey===$slot));
    if ($system!==null) $output['state']=['systemId'=>$humanId,'current'=>array_map($viewArray,$reads->current($system->id)),'history'=>array_map($viewArray,$reads->history($system->id))];
    if ($write) {
        // Only an explicitly selected ACTIVE test System with an entirely unused slot is eligible.
        if ($system->lifecycleState!=='ACTIVE' || $inSlot($reads->history($system->id))!==[]) throw new SystemException('system_component_smoke_fixture_not_fresh');
        $passportRepo=new BitrixPassportRepository(); $passportBefore=$passportRepo->findBySystem($system->id);
        $nonce=bin2hex(random_bytes(8));
        $specA=new ComponentSpec('SSD',$slot,'Component smoke slot','KORSAC smoke A '.$nonce,$concurrency>0 ? null : 'PRIVATE_A_'.bin2hex(random_bytes(16)));
        $specB=new ComponentSpec('SSD',$slot,'Component smoke slot','KORSAC smoke B '.$nonce,$concurrency>0 ? null : 'PRIVATE_B_'.bin2hex(random_bytes(16)));
        $runWorkers=static function(string $mode,string $name,?int $expected=null) use (&$children,$concurrency,$humanId,$slot):int {
            for ($i=0;$i<$concurrency;++$i) {
                $command=[PHP_BINARY]; if (php_ini_loaded_file()) array_push($command,'-c',php_ini_loaded_file());
                array_push($command,__FILE__,'--component-worker','--confirm-write','--system='.$humanId,'--slot='.$slot);
                $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if (!is_resource($process)) throw new SystemException('system_component_smoke_worker_failed');
                $children[]=[$process,$pipes]; $payload=json_encode(['mode'=>$mode,'name'=>$name,'expected'=>$expected],JSON_THROW_ON_ERROR);
                if (fwrite($pipes[0],$payload)!==strlen($payload)) throw new SystemException('system_component_smoke_worker_failed'); fclose($pipes[0]);
            }
            $changed=0; $id=null;
            foreach ($children as [$process,$pipes]) {
                $text=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                if (proc_close($process)!==0) throw new SystemException('system_component_smoke_worker_failed');
                $row=json_decode($text,true,16,JSON_THROW_ON_ERROR);
                if (($row['ok'] ?? false)!==true || !is_bool($row['changed'] ?? null) || !is_int($row['componentId'] ?? null) || $row['componentId']<1 || ($id!==null && $id!==$row['componentId'])) throw new SystemException('system_component_smoke_concurrency_failed');
                $id=$row['componentId']; $changed+=(int)$row['changed'];
            }
            $children=[];
            if ($changed!==1 || $id===null) throw new SystemException('system_component_smoke_concurrency_failed');
            return $id;
        };
        if ($concurrency>0) $firstId=$runWorkers('install',$specA->name);
        else { $first=$service->install($system->id,$specA); if (!$first->created) throw new SystemException('system_component_smoke_install_failed'); $firstId=$first->component->componentId; }
        $before=$inSlot($reads->history($system->id));
        $again=$service->install($system->id,$specA);
        if ($again->created || $again->component->componentId!==$firstId || count($before)!==1 || $before!=$inSlot($reads->history($system->id))) throw new SystemException('system_component_smoke_retry_failed');
        try { $service->install($system->id,$specB); throw new SystemException('system_component_smoke_occupied_failed'); }
        catch (SystemException $error) { if ($error->machineCode!=='system_component_slot_occupied') throw $error; }
        if ($before!=$inSlot($reads->history($system->id))) throw new SystemException('system_component_smoke_occupied_failed');
        if ($concurrency>0) $newId=$runWorkers('replace',$specB->name,$firstId);
        else { $replacement=$service->replace($system->id,$firstId,$specB); if (!$replacement->replaced) throw new SystemException('system_component_smoke_replace_failed'); $newId=$replacement->component->componentId; }
        $history=$inSlot($reads->history($system->id)); $current=$inSlot($reads->current($system->id));
        $retry=$service->replace($system->id,$firstId,$specB);
        if ($retry->replaced || $retry->component->componentId!==$newId || count($history)!==2 || count($current)!==1 || $history!=$inSlot($reads->history($system->id))) throw new SystemException('system_component_smoke_retry_failed');
        [$old,$new]=$history;
        if ($old->componentId!==$firstId || $old->active || $new->componentId!==$newId || !$new->active || $old->removedAt!=$new->installedAt || $new->name!==$specB->name || $current[0]->componentId!==$newId) throw new SystemException('system_component_smoke_history_failed');
        if ($system!=$systems->findById($system->id) || $passportBefore!=$passportRepo->findBySystem($system->id)) throw new SystemException('system_component_smoke_unrelated_mutation');
        $publicChecked=false;
        if ($passportBefore!==null && $passportBefore->state==='ACTIVE') {
            $dto=SystemServiceFactory::publicPassports()->find($passportBefore->publicId)->toArray();
            $matching=array_values(array_filter($dto['components'],static fn(array $view):bool=>$view['name']===$specB->name && $view['slotLabel']===$specB->slotLabel && $view['typeCode']===$specB->typeCode));
            if (count($matching)!==1 || array_keys($dto)!==['publicId','systemId','systemType','modelName','transferredAt','warrantyStartedAt','components']) throw new SystemException('system_component_smoke_public_failed');
            foreach ($dto['components'] as $component) if (array_keys($component)!==['typeCode','slotLabel','name']) throw new SystemException('system_component_smoke_public_failed');
            $json=json_encode($dto,JSON_THROW_ON_ERROR);
            foreach ([$specA->name,$specA->serialNumber,$specB->serialNumber] as $private) if ($private!==null && str_contains($json,$private)) throw new SystemException('system_component_smoke_public_failed');
            $publicChecked=true;
        }
        $health=(new SystemComponentDataHealth())->run(); if (!$health['ok']) throw new SystemException('system_component_smoke_history_failed');
        $output=['ok'=>true,'mode'=>'controlled_write','persistedHistory'=>true,'systemId'=>$humanId,'slot'=>$slot,'historyRows'=>2,'activeRows'=>1,'installRetryVerified'=>true,'replacementRetryVerified'=>true,'unchangedSystemAndPassport'=>true,'publicPassportVerified'=>$publicChecked,'concurrencyWorkers'=>$concurrency,'concurrentInstallAndReplace'=>$concurrency>0,'components'=>$health];
    }
    echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    foreach ($children as [$process,$pipes]) { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
    fwrite(STDERR,json_encode(['ok'=>false,'code'=>$error instanceof SystemException ? $error->machineCode : 'system_component_smoke_failed']).PHP_EOL); exit(1);
}
