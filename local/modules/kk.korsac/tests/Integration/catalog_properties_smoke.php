<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\CatalogPropertyInstaller;
use KK\Korsac\Catalog\CatalogPropertySelfCheck;

$arguments = getopt('', ['iblock:']);
$iblockId = filter_var($arguments['iblock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($iblockId === false) { fwrite(STDERR, "Usage: php catalog_properties_smoke.php --iblock=<ID>\n"); exit(2); }
$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('iblock') || !Loader::includeModule('highloadblock') || !Loader::includeModule('kk.korsac')) { fwrite(STDERR, "Required modules are not available.\n"); exit(2); }
try {
    $gateway = new BitrixCatalogPropertyGateway();
    $installer = new CatalogPropertyInstaller($gateway);
    $first = $installer->installWithResult((int)$iblockId);
    $second = $installer->installWithResult((int)$iblockId);
    $check = (new CatalogPropertySelfCheck($gateway))->run((int)$iblockId);
    $result = ['ok' => $check['ok'] && $second['created'] === 0, 'firstInstall' => $first, 'secondInstall' => $second, 'check' => $check];
} catch (Throwable $error) { $result = ['ok' => false, 'errors' => [$error->getMessage()]]; }
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
