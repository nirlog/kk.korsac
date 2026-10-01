<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Configurator\BitrixCatalogBasePriceProvider;
use KK\Korsac\Configurator\ConfiguratorService;
use KK\Korsac\Configurator\HlOptionViewProvider;
use KK\Korsac\Pricing\ConfigurationPriceCalculator;
use KK\Korsac\Pricing\HlOptionPriceProvider;
use KK\Korsac\Repository\OptionRepository;

$arguments = getopt('', ['iblock:', 'product:', 'selection-json::']);
$iblockId = filter_var($arguments['iblock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$productId = filter_var($arguments['product'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($iblockId === false || $productId === false) {
    fwrite(STDERR, "Usage: php configurator_api_smoke.php --iblock=<ID> --product=<ID> [--selection-json='<JSON>']\n");
    exit(2);
}
try {
    $selection = json_decode((string)($arguments['selection-json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($selection) || (array_is_list($selection) && $selection !== [])) {
        throw new InvalidArgumentException('Selection JSON must be an object');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Invalid --selection-json: {$error->getMessage()}\n");
    exit(2);
}
$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
foreach (['iblock', 'highloadblock', 'catalog', 'kk.korsac'] as $module) {
    if (!Loader::includeModule($module)) { fwrite(STDERR, "Required module {$module} is unavailable.\n"); exit(2); }
}
try {
    $options = new OptionRepository();
    $service = new ConfiguratorService(
        new ProductConfigurationRepository(new BitrixCatalogPropertyGateway(), $options),
        new BitrixCatalogBasePriceProvider(),
        new HlOptionViewProvider($options),
        new ConfigurationPriceCalculator(new HlOptionPriceProvider($options)),
    );
    $result = $selection === [] ? $service->get((int)$iblockId, (int)$productId) : $service->calculate((int)$iblockId, (int)$productId, $selection);
    if ($selection === [] && count($result['groups']) !== 12) { throw new RuntimeException('Expected all 12 canonical groups'); }
    $expectedDelta = $selection === [] ? 0 : 1630000;
    $expectedFinal = $selection === [] ? 3099000 : 4729000;
    if ($result['price']['basePriceMinor'] !== 3099000 || $result['price']['configurationDeltaMinor'] !== $expectedDelta || $result['price']['finalPriceMinor'] !== $expectedFinal) {
        throw new RuntimeException('Unexpected smoke-test prices');
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Configurator API smoke failed: {$error->getMessage()}\n");
    exit(1);
}
