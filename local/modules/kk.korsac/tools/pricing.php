<?php

declare(strict_types=1);

use Bitrix\Catalog\GroupTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use KK\Korsac\Pricing\BitrixPricingPolicyProvider;
use KK\Korsac\Pricing\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Pricing\PriceChannel;
use KK\Korsac\Pricing\PricingConfiguration;
use KK\Korsac\Pricing\PricingPolicy;

$usage = "Usage:\n"
    . "php pricing.php configure --iblock=<ID> --channel=<RETAIL|BUSINESS> --price-type=<ID> --markup-bps=<N> --fixed-adjustment-minor=<N>\n"
    . "php pricing.php show --iblock=<ID> --channel=<RETAIL|BUSINESS>\n";
$command = $argv[1] ?? '';
$arguments = [];
foreach ($argv as $argument) {
    if (preg_match('/^--(iblock|channel|price-type|markup-bps|fixed-adjustment-minor)=(.*)$/D', $argument, $matches) === 1) {
        $arguments[$matches[1]] = $matches[2];
    }
}
$positive = static fn(mixed $value): int|false => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$nonNegative = static fn(mixed $value): int|false => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$iblockId = $positive($arguments['iblock'] ?? null);
try { $channel = PriceChannel::normalize((string)($arguments['channel'] ?? '')); }
catch (Throwable) { $channel = null; }
if (!in_array($command, ['configure', 'show'], true) || $iblockId === false || $channel === null) {
    fwrite(STDERR, $usage); exit(2);
}

$priceTypeId = $positive($arguments['price-type'] ?? null);
$markup = $nonNegative($arguments['markup-bps'] ?? null);
$fixed = $nonNegative($arguments['fixed-adjustment-minor'] ?? null);
if ($command === 'configure' && ($priceTypeId === false || $markup === false || $fixed === false)) {
    fwrite(STDERR, $usage); exit(2);
}

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
foreach (['catalog', 'kk.korsac'] as $module) {
    if (!Loader::includeModule($module)) { fwrite(STDERR, "Required module {$module} is unavailable.\n"); exit(2); }
}

try {
    if ($command === 'configure') {
        new PricingPolicy((int)$markup, (int)$fixed);
        if (!GroupTable::getByPrimary((int)$priceTypeId, ['select' => ['ID']])->fetch()) {
            throw new RuntimeException('Catalog price type does not exist');
        }
        Option::set(PricingConfiguration::MODULE_ID, PricingConfiguration::priceTypeKey((int)$iblockId, $channel), (string)$priceTypeId);
        Option::set(PricingConfiguration::MODULE_ID, PricingConfiguration::markupKey((int)$iblockId, (int)$priceTypeId), (string)$markup);
        Option::set(PricingConfiguration::MODULE_ID, PricingConfiguration::fixedAdjustmentKey((int)$iblockId, (int)$priceTypeId), (string)$fixed);
    }
    $resolved = (new ConfiguredCatalogPriceTypeResolver())->resolve((int)$iblockId, $channel);
    $policy = (new BitrixPricingPolicyProvider())->get((int)$iblockId, $resolved);
    echo json_encode([
        'iblockId'=>(int)$iblockId, 'channel'=>$channel, 'priceTypeId'=>$resolved,
        'markupBasisPoints'=>$policy->markupBasisPoints,
        'systemFixedAdjustmentMinor'=>$policy->systemFixedAdjustmentMinor,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Pricing configuration failed: {$error->getMessage()}\n"); exit(1);
}
