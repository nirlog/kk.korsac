<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\BitrixSchemaGateway;
use KK\Korsac\Install\MigrationRunner;

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

if (ModuleManager::isModuleInstalled('kk.korsac')) {
    fwrite(STDERR, "Precondition failed: kk.korsac must not already be installed. No changes were made.\n");
    exit(2);
}
if (!Loader::includeModule('highloadblock')) {
    fwrite(STDERR, "The highloadblock module is required. No changes were made.\n");
    exit(2);
}

$moduleRoot = dirname(__DIR__, 2);
foreach (['include.php', 'install/index.php', 'lib/Install/MigrationRunner.php'] as $relativePath) {
    if (!is_file($moduleRoot . '/' . $relativePath)) {
        fwrite(STDERR, "Missing module file: {$relativePath}\n");
        exit(2);
    }
}

require_once $moduleRoot . '/install/index.php';
$module = new kk_korsac();
$module->DoInstall();

$installed = ModuleManager::isModuleInstalled('kk.korsac');
$included = $installed && Loader::includeModule('kk.korsac');
$autoloaded = $included && class_exists(MigrationRunner::class);
$result = $autoloaded
    ? (new SchemaSelfCheck(new BitrixSchemaGateway()))->run()
    : ['ok' => false, 'errors' => [['code' => 'install_bootstrap_failed']], 'warnings' => [], 'checkedAt' => date(DATE_ATOM)];

$bitrixException = $APPLICATION->GetException();
$output = [
    'installed' => $installed,
    'included' => $included,
    'autoloaded' => $autoloaded,
    'bitrixError' => $bitrixException?->GetString(),
    'schema' => $result,
];
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

// Deliberately leave the successfully installed module registered and preserve all created HL data.
exit($installed && $included && $autoloaded && $result['ok'] ? 0 : 1);
