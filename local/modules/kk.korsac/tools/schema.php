<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\BitrixSchemaGateway;
use KK\Korsac\Install\OptionMigrationStore;
use KK\Korsac\Install\SchemaMigrationService;
use KK\Korsac\Install\BitrixSnapshotTableGateway;
use KK\Korsac\Install\SnapshotMigrationService;
use KK\Korsac\Install\SystemMigrationService;
use KK\Korsac\Install\BitrixSystemTableGateway;
use KK\Korsac\Health\SystemSchemaSelfCheck;
use KK\Korsac\System\SystemException;
use KK\Korsac\Install\ProductionStatusMigrationService;
use KK\Korsac\Install\BitrixProductionStatusTableGateway;
use KK\Korsac\Install\SqlTableInspector;
use KK\Korsac\Health\ProductionStatusSchemaSelfCheck;
use KK\Korsac\Health\SnapshotSchemaSelfCheck;
use KK\Korsac\System\SystemServiceFactory;
use KK\Korsac\Install\PassportMigrationService;
use KK\Korsac\Install\BitrixPassportTableGateway;
use KK\Korsac\Health\PassportSchemaSelfCheck;
use KK\Korsac\Install\OwnershipMigrationService;
use KK\Korsac\Install\BitrixOwnershipTableGateway;
use KK\Korsac\Install\OwnershipLegacyAssignmentsException;
use KK\Korsac\Health\OwnershipSchemaSelfCheck;

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('highloadblock') || !Loader::includeModule('kk.korsac')) {
    fwrite(STDERR, "Required modules are not available.\n"); exit(2);
}
$gateway = new BitrixSchemaGateway();
$command = $argv[1] ?? 'check';
if ($command === 'migrate') {
    $store = new OptionMigrationStore();
    try {
        $applied = array_merge(
            (new SchemaMigrationService($store, $gateway))->migrate(),
            (new SnapshotMigrationService($store, new BitrixSnapshotTableGateway()))->migrate(),
            (new SystemMigrationService($store, new BitrixSystemTableGateway()))->migrate(),
            (new ProductionStatusMigrationService($store,new BitrixProductionStatusTableGateway(),SystemServiceFactory::registration()))->migrate(),
            (new PassportMigrationService($store,new BitrixPassportTableGateway()))->migrate(),
            (new OwnershipMigrationService($store,new BitrixOwnershipTableGateway()))->migrate(),
        );
    } catch (SystemException|OwnershipLegacyAssignmentsException $error) {
        fwrite(STDERR, json_encode(['ok'=>false, 'error'=>$error->diagnostic()]) . PHP_EOL);
        exit(1);
    }
    echo json_encode(['applied' => $applied], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}
if ($command !== 'check') { fwrite(STDERR, "Usage: php schema.php [check|migrate]\n"); exit(2); }
$result = (new SchemaSelfCheck($gateway))->run();
$systems = (new SystemSchemaSelfCheck(new BitrixSystemTableGateway()))->run();
$result['systems'] = $systems;
$result['ok'] = $result['ok'] && $systems['ok'];
$result['errors'] = array_merge($result['errors'], $systems['errors']);
foreach (['snapshots'=>(new SnapshotSchemaSelfCheck(static fn(string $table):?array=>(new SqlTableInspector())->describe($table)))->run(), 'production'=>(new ProductionStatusSchemaSelfCheck(new BitrixProductionStatusTableGateway()))->run(), 'saleHandler'=>SystemServiceFactory::registration()->check(), 'passports'=>(new PassportSchemaSelfCheck(new BitrixPassportTableGateway()))->run(), 'ownership'=>(new OwnershipSchemaSelfCheck(new BitrixOwnershipTableGateway()))->run()] as $name=>$check) {
    $result[$name]=$check; $result['ok']=$result['ok'] && $check['ok']; $result['errors']=array_merge($result['errors'],$check['errors']);
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
