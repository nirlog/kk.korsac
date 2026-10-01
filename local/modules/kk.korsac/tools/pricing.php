<?php

declare(strict_types=1);

use Bitrix\Catalog\GroupTable;
use Bitrix\Main\Loader;
use KK\Korsac\Pricing\Policy\BitrixPricingPolicyProvider;
use KK\Korsac\Pricing\Policy\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Pricing\Policy\PriceChannel;
use KK\Korsac\Pricing\Policy\PricingConfigurationWriter;
use KK\Korsac\Pricing\Policy\PricingPolicy;

$command = $argv[1] ?? '';
$arguments = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $matches) === 1) { $arguments[$matches[1]] = $matches[2]; }
}
$iblockId = filter_var($arguments['iblock'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$channel = strtoupper((string)($arguments['channel'] ?? ''));
if ($iblockId === false || !in_array($channel, [PriceChannel::RETAIL, PriceChannel::BUSINESS], true) || !in_array($command, ['configure','show'], true)) {
    fwrite(STDERR, "Usage: php pricing.php configure --iblock=<ID> --channel=<RETAIL|BUSINESS> --price-type=<ID> --markup-bps=<N> --fixed-adjustment-minor=<N>\n       php pricing.php show --iblock=<ID> --channel=<RETAIL|BUSINESS>\n"); exit(2);
}
$root = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $root;
require $root . '/bitrix/modules/main/include/prolog_before.php';
foreach (['catalog','kk.korsac'] as $module) { if (!Loader::includeModule($module)) { fwrite(STDERR, "Module {$module} unavailable\n"); exit(2); } }
try {
    if ($command === 'configure') {
        $priceTypeId = filter_var($arguments['price-type'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $markup = filter_var($arguments['markup-bps'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        $fixed = filter_var($arguments['fixed-adjustment-minor'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($priceTypeId === false || $markup === false || $fixed === false) { throw new InvalidArgumentException('Invalid pricing values'); }
        if (!GroupTable::getByPrimary((int)$priceTypeId, ['select'=>['ID']])->fetch()) { throw new InvalidArgumentException('Catalog price type does not exist'); }
        (new PricingConfigurationWriter())->configure((int)$iblockId, $channel, (int)$priceTypeId, new PricingPolicy((int)$markup, (int)$fixed));
    }
    $resolved = (new ConfiguredCatalogPriceTypeResolver())->resolve((int)$iblockId, $channel);
    $policy = (new BitrixPricingPolicyProvider())->get((int)$iblockId, $resolved);
    echo json_encode(['iblockId'=>(int)$iblockId,'channel'=>$channel,'priceTypeId'=>$resolved,'markupBasisPoints'=>$policy->markupBasisPoints,'systemFixedAdjustmentMinor'=>$policy->systemFixedAdjustmentMinor], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
