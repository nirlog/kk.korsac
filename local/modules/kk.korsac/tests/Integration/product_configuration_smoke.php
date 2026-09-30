<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationException;
use KK\Korsac\Catalog\ProductConfigurationRepository;

$arguments = getopt('', ['iblock:', 'product:']);
$iblockId = filter_var($arguments['iblock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$productId = filter_var($arguments['product'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($iblockId === false || $productId === false) { fwrite(STDERR, "Usage: php product_configuration_smoke.php --iblock=<ID> --product=<ID>\n"); exit(2); }
$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('iblock') || !Loader::includeModule('highloadblock') || !Loader::includeModule('kk.korsac')) { fwrite(STDERR, "Required modules are not available.\n"); exit(2); }
try {
    $configuration = (new ProductConfigurationRepository(new BitrixCatalogPropertyGateway()))->get((int)$iblockId, (int)$productId);
    $result = ['ok' => true, 'iblockId' => (int)$iblockId, 'productId' => (int)$productId, 'configuration' => $configuration];
} catch (ProductConfigurationException $error) {
    $result = ['ok' => false, 'iblockId' => (int)$iblockId, 'productId' => (int)$productId, 'error' => $error->diagnostic()];
} catch (Throwable $error) { $result = ['ok' => false, 'errors' => [$error->getMessage()]]; }
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
