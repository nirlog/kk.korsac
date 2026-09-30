<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationException;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Pricing\ConfigurationPriceCalculator;
use KK\Korsac\Pricing\ConfigurationPricingException;
use KK\Korsac\Pricing\ConfigurationSelection;
use KK\Korsac\Pricing\HlOptionPriceProvider;

$arguments = getopt('', ['iblock:', 'product:', 'base-price-minor:', 'selection-json::']);
$iblockId = filter_var($arguments['iblock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$productId = filter_var($arguments['product'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$basePrice = filter_var($arguments['base-price-minor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($iblockId === false || $productId === false || $basePrice === false) {
    fwrite(STDERR, "Usage: php configuration_pricing_smoke.php --iblock=<ID> --product=<ID> --base-price-minor=<INTEGER> [--selection-json='<JSON>']\n");
    exit(2);
}
$rawSelection = $arguments['selection-json'] ?? '{}';
try {
    $selectionInput = json_decode((string)$rawSelection, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($selectionInput) || array_is_list($selectionInput) && $selectionInput !== []) {
        throw new InvalidArgumentException('Selection JSON must be an object.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Invalid --selection-json: {$error->getMessage()}\n");
    exit(2);
}

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('iblock') || !Loader::includeModule('highloadblock') || !Loader::includeModule('kk.korsac')) {
    fwrite(STDERR, "Required modules are not available.\n");
    exit(2);
}
try {
    $configuration = (new ProductConfigurationRepository(new BitrixCatalogPropertyGateway()))->get((int)$iblockId, (int)$productId);
    $selection = ConfigurationSelection::fromArray($configuration, $selectionInput);
    $price = (new ConfigurationPriceCalculator(new HlOptionPriceProvider()))->calculate($configuration, $selection, (int)$basePrice);
    $result = ['ok' => true, 'iblockId' => (int)$iblockId, 'productId' => (int)$productId, 'selection' => $selection->toArray(), 'price' => $price];
} catch (ProductConfigurationException|ConfigurationPricingException $error) {
    $result = ['ok' => false, 'iblockId' => (int)$iblockId, 'productId' => (int)$productId, 'error' => $error->diagnostic()];
} catch (Throwable $error) {
    $result = ['ok' => false, 'errors' => [$error->getMessage()]];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
