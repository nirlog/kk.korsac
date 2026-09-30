<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\CatalogPropertyInstaller;
use KK\Korsac\Catalog\CatalogPropertySelfCheck;

$usage = "Usage:\nphp catalog.php install-properties --iblock=<ID>\nphp catalog.php check-properties --iblock=<ID>\n";
$command = $argv[1] ?? '';
$rawIblockId = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--iblock=')) { $rawIblockId = substr($argument, 9); }
}
$iblockId = filter_var($rawIblockId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!in_array($command, ['install-properties', 'check-properties'], true) || $iblockId === false) {
    fwrite(STDERR, $usage);
    exit(2);
}
$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('iblock') || !Loader::includeModule('highloadblock') || !Loader::includeModule('kk.korsac')) {
    fwrite(STDERR, "Required modules are not available.\n");
    exit(2);
}

try {
    $gateway = new BitrixCatalogPropertyGateway();
    if ($command === 'install-properties') {
        $counts = (new CatalogPropertyInstaller($gateway))->installWithResult((int)$iblockId);
        $result = ['ok' => true, 'iblockId' => (int)$iblockId] + $counts + ['errors' => []];
    } else {
        $result = (new CatalogPropertySelfCheck($gateway))->run((int)$iblockId);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'iblockId' => (int)$iblockId, 'errors' => [$error->getMessage()]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(1);
}
