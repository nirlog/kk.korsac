<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Sale\Order;
use KK\Korsac\Health\ProductionStatusSchemaSelfCheck;
use KK\Korsac\Install\BitrixProductionStatusTableGateway;
use KK\Korsac\System\BitrixOrderProjectionAdapter;
use KK\Korsac\System\BitrixSystemRepository;
use KK\Korsac\System\BitrixProductionStatusGateway;
use KK\Korsac\System\BitrixSystemSettingsGateway;
use KK\Korsac\System\SystemServiceFactory;
use KK\Korsac\System\SystemException;

$documentRoot=($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__,5);
$_SERVER['DOCUMENT_ROOT']=$documentRoot;
if (!is_file($documentRoot.'/bitrix/modules/main/include/prolog_before.php')) { fwrite(STDERR,"{\"ok\":false,\"code\":\"bitrix_environment_required\"}\n"); exit(2); }
require $documentRoot.'/bitrix/modules/main/include/prolog_before.php';
try {
    if (!Loader::includeModule('kk.korsac') || !Loader::includeModule('sale')) throw new SystemException('system_sale_required');
    $write=false; $orderId=null;
    foreach (array_slice($argv,1) as $arg) {
        if ($arg==='--confirm-write') { $write=true; continue; }
        if (preg_match('/\A--order=([0-9]+)\z/D',$arg,$match) && $orderId===null && (int)$match[1]>=1 && (int)$match[1]<=2147483647) { $orderId=(int)$match[1]; continue; }
        throw new SystemException('system_cli_arguments_invalid');
    }
    if ($write && $orderId===null) throw new SystemException('system_cli_arguments_invalid');
    $schema=(new ProductionStatusSchemaSelfCheck(new BitrixProductionStatusTableGateway()))->run();
    $handler=SystemServiceFactory::registration()->check(); $settings=SystemServiceFactory::configuration()->read();
    $configOk=$settings->triggerStatusId==='' ? !$settings->enabled : (new BitrixSystemSettingsGateway())->isOrderStatus($settings->triggerStatusId);
    if (!$schema['ok'] || !$handler['ok'] || !$configOk) { echo json_encode(['ok'=>false,'schema'=>$schema,'handler'=>$handler,'automation'=>['enabled'=>$settings->enabled,'triggerStatusId'=>$settings->triggerStatusId,'valid'=>$configOk]],JSON_PRETTY_PRINT),PHP_EOL; exit(1); }
    $result=['ok'=>true,'mode'=>'read_only','schema'=>$schema,'handler'=>$handler,'automation'=>['enabled'=>$settings->enabled,'triggerStatusId'=>$settings->triggerStatusId]];
    if ($orderId!==null) {
        $order=Order::load($orderId);
        if ($order===null) throw new SystemException('system_order_not_found');
        $projection=(new BitrixOrderProjectionAdapter())->project($order); $service=SystemServiceFactory::synchronizer();
        $result['order']=$service->inspect($projection);
        if ($write) {
            // Explicitly persists Systems and status/history on a dedicated test Order; no Order edits/saves.
            $first=$service->synchronize($projection); $statuses=new BitrixProductionStatusGateway(); $before=[];
            foreach ($first->systemIds as $humanId) {
                $system=(new BitrixSystemRepository())->findBySystemId($humanId); $state=$statuses->findCurrent($system->id); $history=$statuses->history($system->id);
                if ($state===null || $history===[] || $history[0]->revision!==1 || $history[0]->fromStatusCode!==null || $history[0]->toStatusCode!=='WAREHOUSE' || $history[0]->sourceCode!=='SALE_AUTOMATION') throw new SystemException('system_sale_smoke_status_failed');
                $before[$humanId]=[$state->statusCode,$state->revision,count($history)];
            }
            // Instrument repeat allocations with the actual sequence allocator to distinguish unrelated workers.
            $allocator=new class implements \KK\Korsac\System\SystemSequenceAllocatorInterface {
                public int $calls=0;
                public function allocate(string $period):int { ++$this->calls; return (new \KK\Korsac\System\BitrixSystemSequenceAllocator())->allocate($period); }
            };
            $repo=new BitrixSystemRepository(); $snapshots=new \KK\Korsac\Cart\BitrixConfigurationSnapshotRepository();
            $repeatService=new \KK\Korsac\System\OrderSystemSynchronizationService(SystemServiceFactory::configuration(),$repo,new \KK\Korsac\System\SystemCreationService($repo,new \KK\Korsac\System\SystemIdGenerator($allocator),$snapshots),$snapshots,SystemServiceFactory::production());
            $repeat=$repeatService->synchronize($projection);
            if ($first->physicalUnitCount===0 || $repeat->systemIds!==$first->systemIds || $repeat->createdSystemCount!==0 || $repeat->existingSystemCount!==$repeat->physicalUnitCount || $allocator->calls!==0 || count($repo->findByOrder($orderId))!==$first->physicalUnitCount) throw new SystemException('system_sale_smoke_idempotency_failed');
            foreach ($repeat->systemIds as $humanId) { $system=$repo->findBySystemId($humanId); $state=$statuses->findCurrent($system->id); if ($before[$humanId]!==[$state->statusCode,$state->revision,count($statuses->history($system->id))]) throw new SystemException('system_sale_smoke_status_failed'); }
            $result['mode']='controlled_write'; $result['synchronization']=$first->toArray(); $result['repeat']=$repeat->toArray(); $result['repeatSequenceAllocations']=$allocator->calls;
        }
    }
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR,json_encode(['ok'=>false,'code'=>$error instanceof SystemException ? $error->machineCode : 'system_sale_smoke_failed']).PHP_EOL); exit(1); }
