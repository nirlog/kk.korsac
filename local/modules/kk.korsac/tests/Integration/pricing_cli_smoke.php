<?php

declare(strict_types=1);

$arguments = getopt('', ['iblock:', 'channel:', 'price-type:', 'markup-bps:', 'fixed-adjustment-minor:']);
$required = ['iblock', 'channel', 'price-type', 'markup-bps', 'fixed-adjustment-minor'];
foreach ($required as $name) {
    if (!array_key_exists($name, $arguments)) {
        fwrite(STDERR, "Usage: php pricing_cli_smoke.php --iblock=<ID> --channel=<RETAIL|BUSINESS> --price-type=<ID> --markup-bps=<N> --fixed-adjustment-minor=<N>\n");
        exit(2);
    }
}

$tool = dirname(__DIR__, 2) . '/tools/pricing.php';
$common = sprintf(
    '--iblock=%s --channel=%s',
    escapeshellarg((string)$arguments['iblock']),
    escapeshellarg((string)$arguments['channel']),
);
$configure = sprintf(
    '%s %s configure %s --price-type=%s --markup-bps=%s --fixed-adjustment-minor=%s 2>&1',
    escapeshellarg(PHP_BINARY), escapeshellarg($tool), $common,
    escapeshellarg((string)$arguments['price-type']),
    escapeshellarg((string)$arguments['markup-bps']),
    escapeshellarg((string)$arguments['fixed-adjustment-minor']),
);
$show = sprintf('%s %s show %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($tool), $common);

foreach (['configure'=>$configure, 'show'=>$show] as $operation => $command) {
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, "pricing.php {$operation} failed:\n" . implode("\n", $output) . "\n");
        exit(1);
    }
    try { $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { fwrite(STDERR, "pricing.php {$operation} returned invalid JSON: {$error->getMessage()}\n"); exit(1); }
    foreach (['iblockId','channel','priceTypeId','markupBasisPoints','systemFixedAdjustmentMinor'] as $field) {
        if (!array_key_exists($field, $result)) { fwrite(STDERR, "pricing.php {$operation} omitted {$field}\n"); exit(1); }
    }
}

echo json_encode(['ok'=>true, 'operations'=>['configure','show']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
