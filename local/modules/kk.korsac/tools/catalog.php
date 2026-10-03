<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\CatalogPropertyInstaller;
use KK\Korsac\Catalog\CatalogPropertySelfCheck;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Catalog\ProductPresentationRepository;
use KK\Korsac\Catalog\ProductPresentationSelfCheck;
use KK\Korsac\Configurator\HlOptionViewProvider;
use KK\Korsac\Repository\OptionRepository;

$usage = "Usage:\nphp catalog.php install-properties --iblock=<ID>\nphp catalog.php check-properties --iblock=<ID>\nphp catalog.php check-presentation --iblock=<ID> --product=<ID>\n";
$command = $argv[1] ?? '';
$rawIblockId = null;
$rawProductId = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--iblock=')) { $rawIblockId = substr($argument, 9); }
    if (str_starts_with($argument, '--product=')) { $rawProductId = substr($argument, 10); }
}
$iblockId = filter_var($rawIblockId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$productId = filter_var($rawProductId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!in_array($command, ['install-properties', 'check-properties', 'check-presentation'], true) || $iblockId === false || ($command === 'check-presentation' && $productId === false)) {
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
    } elseif ($command === 'check-properties') {
        $result = (new CatalogPropertySelfCheck($gateway))->run((int)$iblockId);
    } else {
        $options = new OptionRepository();
        $result = (new ProductPresentationSelfCheck(
            new ProductPresentationRepository($gateway),
            new ProductConfigurationRepository($gateway, $options),
            new HlOptionViewProvider($options),
        ))->run((int)$iblockId, (int)$productId);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'iblockId' => (int)$iblockId, 'errors' => [$error->getMessage()]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(1);
}
