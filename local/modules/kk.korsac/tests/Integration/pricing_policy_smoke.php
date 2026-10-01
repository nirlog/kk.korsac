<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Pricing\DefaultCatalogPriceCalculator;
use KK\Korsac\Pricing\HlOptionPriceProvider;
use KK\Korsac\Pricing\Policy\BitrixPricingPolicyProvider;
use KK\Korsac\Pricing\Policy\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Pricing\Policy\PriceChannel;
use KK\Korsac\Pricing\RetailOptionPriceProvider;
use KK\Korsac\Repository\OptionRepository;

$arguments = getopt('', ['iblock:', 'product:', 'channel::']);
$iblockId = filter_var($arguments['iblock'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$productId = filter_var($arguments['product'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$channel = strtoupper((string)($arguments['channel'] ?? PriceChannel::RETAIL));
if ($iblockId === false || $productId === false || !in_array($channel, [PriceChannel::RETAIL, PriceChannel::BUSINESS], true)) { fwrite(STDERR, "Usage: php pricing_policy_smoke.php --iblock=<ID> --product=<ID> [--channel=RETAIL]\n"); exit(2); }
$root = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $root;
require $root . '/bitrix/modules/main/include/prolog_before.php';
foreach (['iblock','highloadblock','catalog','kk.korsac'] as $module) { if (!Loader::includeModule($module)) { fwrite(STDERR, "Module {$module} unavailable\n"); exit(2); } }
try {
    $options = new OptionRepository();
    $configuration = (new ProductConfigurationRepository(new BitrixCatalogPropertyGateway(), $options))->get((int)$iblockId, (int)$productId);
    $priceTypeId = (new ConfiguredCatalogPriceTypeResolver())->resolve((int)$iblockId, $channel);
    $policy = (new BitrixPricingPolicyProvider())->get((int)$iblockId, $priceTypeId);
    $retail = new RetailOptionPriceProvider(new HlOptionPriceProvider($options), $policy);
    $default = (new DefaultCatalogPriceCalculator($retail))->calculate($configuration, $policy)->toArray();
    echo json_encode(['iblockId'=>(int)$iblockId,'productId'=>(int)$productId,'channel'=>$channel,'priceTypeId'=>$priceTypeId,'policy'=>['markupBasisPoints'=>$policy->markupBasisPoints,'systemFixedAdjustmentMinor'=>$policy->systemFixedAdjustmentMinor],'defaultCatalogPrice'=>$default], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, "Pricing policy smoke failed: {$error->getMessage()}\n"); exit(1); }
