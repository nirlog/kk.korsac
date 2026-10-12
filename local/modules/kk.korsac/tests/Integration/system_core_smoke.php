<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use KK\Korsac\Cart\BitrixConfigurationSnapshotRepository;
use KK\Korsac\Health\SystemSchemaSelfCheck;
use KK\Korsac\Install\BitrixSystemTableGateway;
use KK\Korsac\System\BitrixSystemComponentRepository;
use KK\Korsac\System\BitrixSystemRepository;
use KK\Korsac\System\BitrixSystemSequenceAllocator;
use KK\Korsac\System\SystemComponent;
use KK\Korsac\System\SystemCreationService;
use KK\Korsac\System\SystemException;
use KK\Korsac\System\SystemIdGenerator;
use KK\Korsac\System\SystemSequenceTable;

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
if (!is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(STDERR, "{\"ok\":false,\"code\":\"bitrix_environment_required\"}\n"); exit(2);
}
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('kk.korsac')) { fwrite(STDERR, "{\"ok\":false,\"code\":\"system_module_required\"}\n"); exit(2); }

$write = in_array('--confirm-write', $argv, true);
$worker = in_array('--sequence-worker', $argv, true);
$concurrency = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--confirm-write','--sequence-worker'], true)) continue;
    if (preg_match('/\A--concurrency=([2-8])\z/D', $argument, $match)) { $concurrency = (int)$match[1]; continue; }
    fwrite(STDERR, "Usage: php system_core_smoke.php [--confirm-write [--concurrency=2..8]]\n"); exit(2);
}
if (($worker || $concurrency > 0) && !$write) { fwrite(STDERR, "Explicit --confirm-write is required.\n"); exit(2); }
$connection = Application::getConnection();
$transaction = false;
$children = [];
try {
    $ids = new SystemIdGenerator(new BitrixSystemSequenceAllocator());
    if ($worker) {
        echo json_encode(['systemId'=>$ids->generate(new DateTimeImmutable('now'))]), PHP_EOL;
        exit(0);
    }
    $health = (new SystemSchemaSelfCheck(new BitrixSystemTableGateway()))->run();
    if (!$health['ok']) { echo json_encode($health, JSON_PRETTY_PRINT), PHP_EOL; exit(1); }
    if (!$write) { echo json_encode(['ok'=>true,'mode'=>'read_only','schema'=>$health], JSON_PRETTY_PRINT), PHP_EOL; exit(0); }

    $allocated = [];
    for ($i = 0; $i < $concurrency; ++$i) {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
        array_push($command, __FILE__, '--confirm-write', '--sequence-worker');
        $pipes = [];
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new SystemException('system_smoke_worker_failed');
        fclose($pipes[0]); $children[] = [$process,$pipes];
    }
    foreach ($children as [$process,$pipes]) {
        $output = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) throw new SystemException('system_smoke_worker_failed');
        $row = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $allocated[] = $row['systemId'];
    }
    $children = [];
    if (count(array_unique($allocated)) !== $concurrency) throw new SystemException('system_smoke_sequence_collision');

    $systems = new BitrixSystemRepository();
    // Synthetic identities only: no Order is created or customer row changed.
    $input = ['SITE_ID'=>'test','ORDER_ID'=>4294967295,'ORDER_BASKET_ID'=>random_int(2147483648,4294967295), 'ORDER_UNIT_INDEX'=>1, 'SYSTEM_TYPE'=>'CUSTOM','MODEL_NAME'=>'KORSAC System core smoke fixture'];
    if ($systems->findByOrderUnit($input['ORDER_ID'],$input['ORDER_BASKET_ID'],1) !== null) throw new SystemException('system_smoke_fixture_collision');
    $service = new SystemCreationService($systems,$ids,new BitrixConfigurationSnapshotRepository());
    $connection->startTransaction(); $transaction = true;
    $system = $service->create($input);
    if ($system->userId !== null || $system->lifecycleState !== 'ACTIVE' || $systems->findById($system->id)?->systemId !== $system->systemId || $systems->findBySystemId($system->systemId)?->id !== $system->id || $service->create($input)->id !== $system->id) throw new SystemException('system_smoke_creation_failed');
    $now = new DateTimeImmutable('now'); $components = new BitrixSystemComponentRepository();
    $active = $components->create(new SystemComponent(0,$system->id,'SSD','SSD_1','SSD 1','Synthetic disk',null,$now,null,$now,$now));
    $removed = $components->create(new SystemComponent(0,$system->id,'SSD','SSD_2','SSD 2','Removed synthetic disk',null,$now,$now,$now,$now));
    $history = $components->findForSystem($system->id);
    if ($active->systemInternalId !== $system->id || $removed->systemInternalId !== $system->id || count($history) !== 2 || count($components->findForSystem($system->id,true)) !== 1) throw new SystemException('system_smoke_component_failed');
    foreach ($history as $component) {
        if ($component->systemInternalId !== $system->id) throw new SystemException('system_smoke_component_failed');
    }
    $connection->rollbackTransaction(); $transaction = false;
    if ($systems->findBySystemId($system->systemId) !== null || $components->findForSystem($system->id) !== []) throw new SystemException('system_smoke_rollback_failed');
    $period = substr($system->systemId,3,4);
    $sequence = SystemSequenceTable::getByPrimary($period)->fetch();
    if (!$sequence || (int)$sequence['LAST_VALUE'] < (int)substr($system->systemId,-4)) throw new SystemException('system_smoke_sequence_reused');
    echo json_encode(['ok'=>true,'mode'=>'controlled_write','rolledBack'=>true,'sequenceNumbersConsumed'=>1+$concurrency,'concurrentAllocations'=>$concurrency],JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $error) {
    if ($transaction) { try { $connection->rollbackTransaction(); } catch (Throwable) {} }
    foreach ($children as [$process,$pipes]) {
        if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    }
    $code = $error instanceof SystemException ? $error->machineCode : 'system_smoke_failed';
    fwrite(STDERR, json_encode(['ok'=>false,'code'=>$code]) . PHP_EOL); exit(1);
}
