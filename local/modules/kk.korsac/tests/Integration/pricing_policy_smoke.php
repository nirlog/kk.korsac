<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Pricing\BitrixPricingPolicyProvider;
use KK\Korsac\Pricing\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Pricing\DefaultCatalogPriceCalculator;
use KK\Korsac\Pricing\HlOptionPriceProvider;
use KK\Korsac\Pricing\PriceChannel;
use KK\Korsac\Pricing\RetailOptionPriceProvider;
use KK\Korsac\Repository\OptionRepository;

$arguments = getopt('', ['iblock:', 'product:', 'channel:']);
$iblockId = filter_var($arguments['iblock'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$productId = filter_var($arguments['product'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$rawChannel = (string)($arguments['channel'] ?? '');
if ($iblockId === false || $productId === false || trim($rawChannel) === '') {
    fwrite(STDERR, "Usage: php pricing_policy_smoke.php --iblock=<ID> --product=<ID> --channel=<RETAIL|BUSINESS>\n"); exit(2);
}
$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
foreach (['iblock','highloadblock','catalog','kk.korsac'] as $module) {
    if (!Loader::includeModule($module)) { fwrite(STDERR, "Required module {$module} is unavailable.\n"); exit(2); }
}
try { $channel = PriceChannel::normalize($rawChannel); }
catch (Throwable) { fwrite(STDERR, "Usage: php pricing_policy_smoke.php --iblock=<ID> --product=<ID> --channel=<RETAIL|BUSINESS>\n"); exit(2); }
try {
    $priceTypeId = (new ConfiguredCatalogPriceTypeResolver())->resolve((int)$iblockId, $channel);
    $policy = (new BitrixPricingPolicyProvider())->get((int)$iblockId, $priceTypeId);
    $options = new OptionRepository();
    $configuration = (new ProductConfigurationRepository(new BitrixCatalogPropertyGateway(), $options))->get((int)$iblockId, (int)$productId);
    $default = (new DefaultCatalogPriceCalculator(new RetailOptionPriceProvider(new HlOptionPriceProvider($options), $policy), $policy))->calculate($configuration);
    echo json_encode([
        'iblockId'=>(int)$iblockId, 'productId'=>(int)$productId, 'channel'=>$channel, 'priceTypeId'=>$priceTypeId,
        'policy'=>['markupBasisPoints'=>$policy->markupBasisPoints,'systemFixedAdjustmentMinor'=>$policy->systemFixedAdjustmentMinor],
        'defaultCatalogPrice'=>$default,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Pricing policy smoke failed: {$error->getMessage()}\n"); exit(1);
}
