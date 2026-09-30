<?php

declare(strict_types=1);

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\DateTime;
use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\BitrixSchemaGateway;
use KK\Korsac\Repository\OptionRepository;
use KK\Korsac\Repository\OptionTypeRegistry;

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

$types = ['ram'=>'RAM', 'hdd'=>'HDD', 'os'=>'OS', 'software'=>'SOFTWARE', 'service'=>'SERVICE'];
$output = ['ok'=>false, 'created'=>array_fill_keys(array_keys($types), 0), 'checks'=>[
    'repositoryRead'=>false, 'schemaWithFixtures'=>false, 'cleanup'=>false, 'schemaAfterCleanup'=>false,
], 'errors'=>[]];
$created = []; $classes = []; $preconditionsPassed = false;
try {
    if (!ModuleManager::isModuleInstalled('kk.korsac') || !Loader::includeModule('highloadblock') || !Loader::includeModule('kk.korsac')) throw new RuntimeException('Required modules are not installed.');
    $gateway = new BitrixSchemaGateway();
    $initial = (new SchemaSelfCheck($gateway))->run();
    if (!$initial['ok']) throw new RuntimeException('Initial schema is unhealthy: ' . json_encode($initial['errors']));
    $preconditionsPassed = true;
    $prefix = 'KK_ACCEPT_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(5));
    foreach ($types as $key => $type) {
        $blockName = OptionTypeRegistry::blockName($type);
        $block = HighloadBlockTable::getList(['filter'=>['=NAME'=>$blockName], 'limit'=>1])->fetch();
        if (!$block) throw new RuntimeException("Missing {$blockName}");
        $class = HighloadBlockTable::compileEntity($block)->getDataClass();
        $classes[$key] = $class;
        $xmlId = $prefix . '_' . strtoupper($key);
        $now = new DateTime();
        $result = $class::add(['UF_XML_ID'=>$xmlId, 'UF_NAME'=>"Acceptance {$type}", 'UF_PUBLIC_NAME'=>"Acceptance {$type}", 'UF_ACTIVE'=>1, 'UF_SORT'=>500, 'UF_PRICE'=>1234.50, 'UF_CREATED_AT'=>$now, 'UF_UPDATED_AT'=>$now]);
        if (!$result->isSuccess()) throw new RuntimeException("Cannot create {$blockName}: " . implode('; ', $result->getErrorMessages()));
        $created[$key] = ['id'=>(int)$result->getId(), 'xmlId'=>$xmlId]; $output['created'][$key] = 1;
    }
    $repository = new OptionRepository();
    foreach ($types as $key => $type) {
        $row = $repository->findByTypeAndXmlId($type, $created[$key]['xmlId']);
        if ($row === null || (float)$row['UF_PRICE'] !== 1234.5) throw new RuntimeException("Repository/price check failed for {$type}");
    }
    $output['checks']['repositoryRead'] = true;
    $withFixtures = (new SchemaSelfCheck($gateway))->run();
    if (!$withFixtures['ok']) throw new RuntimeException('Self-check with fixtures failed: ' . json_encode($withFixtures['errors']));
    $output['checks']['schemaWithFixtures'] = true;
} catch (Throwable $e) { $output['errors'][] = $e->getMessage(); }
finally {
    if ($preconditionsPassed) {
        $cleanup = true;
        foreach (array_reverse(array_keys($created)) as $key) {
            try { $result = $classes[$key]::delete($created[$key]['id']); if (!$result->isSuccess()) throw new RuntimeException(implode('; ', $result->getErrorMessages())); }
            catch (Throwable $e) { $cleanup=false; $output['errors'][]="Cleanup {$key}: {$e->getMessage()}"; }
        }
        foreach ($created as $key => $fixture) if ($classes[$key]::getList(['filter'=>['=UF_XML_ID'=>$fixture['xmlId']], 'limit'=>1])->fetch()) { $cleanup=false; $output['errors'][]="Fixture remains: {$key}"; }
        $output['checks']['cleanup']=$cleanup;
        $after=(new SchemaSelfCheck(new BitrixSchemaGateway()))->run(); $output['checks']['schemaAfterCleanup']=$after['ok'];
        if (!$after['ok']) $output['errors'][]='Post-cleanup self-check failed: '.json_encode($after['errors']);
    }
}
$output['ok']=$output['errors']===[] && !in_array(false,$output['checks'],true);
echo json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($output['ok'] ? 0 : 1);
