<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\BitrixSchemaGateway;
use KK\Korsac\Install\OptionMigrationStore;
use KK\Korsac\Install\SchemaMigrationService;

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('highloadblock') || !Loader::includeModule('kk.korsac')) {
    fwrite(STDERR, "Required modules are not available.\n"); exit(2);
}
$gateway = new BitrixSchemaGateway();
$command = $argv[1] ?? 'check';
if ($command === 'migrate') {
    $applied = (new SchemaMigrationService(new OptionMigrationStore(), $gateway))->migrate();
    echo json_encode(['applied' => $applied], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}
if ($command !== 'check') { fwrite(STDERR, "Usage: php schema.php [check|migrate]\n"); exit(2); }
$result = (new SchemaSelfCheck($gateway))->run();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
