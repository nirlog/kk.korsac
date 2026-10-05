<?php

declare(strict_types=1);

use KK\Korsac\Component\ConfiguratorParameters;

$test('configurator component strictly normalizes product identity', static function () use ($assert): void {
    $valid = ConfiguratorParameters::normalize(['IBLOCK_ID' => '2', 'PRODUCT_ID' => 367], static fn(): string => 'fixed');
    $assert($valid['valid'] && $valid['iblockId'] === 2 && $valid['productId'] === 367);
    foreach ([0, -1, '0', '-1', '367foo', ' 367', 2.5, null] as $invalid) {
        $result = ConfiguratorParameters::normalize(['IBLOCK_ID' => $invalid, 'PRODUCT_ID' => 367], static fn(): string => 'fixed');
        $assert(!$result['valid'], 'Malformed IBLOCK_ID was accepted: ' . var_export($invalid, true));
    }
});

$test('configurator component clamps debounce and uses its default', static function () use ($assert): void {
    $make = static fn(mixed $value): array => ConfiguratorParameters::normalize(['IBLOCK_ID' => 2, 'PRODUCT_ID' => 367, 'DEBOUNCE_MS' => $value], static fn(): string => 'fixed');
    $assert($make(null)['debounceMs'] === 150);
    $assert($make(-10)['debounceMs'] === 0);
    $assert($make('0')['debounceMs'] === 0);
    $assert($make(2001)['debounceMs'] === 2000);
    $assert($make('not-a-number')['debounceMs'] === 150);
});

$test('configurator component creates safe unique-capable DOM identifiers', static function () use ($assert): void {
    $first = ConfiguratorParameters::normalize(['IBLOCK_ID' => 2, 'PRODUCT_ID' => 367], static fn(): string => '0123456789abcdef');
    $second = ConfiguratorParameters::normalize(['IBLOCK_ID' => 2, 'PRODUCT_ID' => 367], static fn(): string => 'fedcba9876543210');
    $assert($first['domId'] !== $second['domId']);
    $assert(preg_match('/^kk-korsac-configurator-[a-z0-9]{16}$/', $first['domId']) === 1);
    $custom = ConfiguratorParameters::normalize(['IBLOCK_ID' => 2, 'PRODUCT_ID' => 367, 'DOM_ID' => 'product_367-main'], static fn(): string => 'unused');
    $assert($custom['domId'] === 'product_367-main');
    $unsafe = ConfiguratorParameters::normalize(['IBLOCK_ID' => 2, 'PRODUCT_ID' => 367, 'DOM_ID' => '</script>'], static fn(): string => 'safe');
    $assert($unsafe['domId'] === 'kk-korsac-configurator-safe');
});

$test('configurator template safely serializes bootstrap and delegates to renderer', static function () use ($assert): void {
    $moduleRoot = dirname(__DIR__, 2);
    $template = (string)file_get_contents($moduleRoot . '/install/components/kk/korsac.configurator/templates/.default/template.php');
    $component = (string)file_get_contents($moduleRoot . '/install/components/kk/korsac.configurator/component.php');
    $assert(str_contains($template, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT'));
    $assert(str_contains($template, 'Promise.resolve(renderer.mount()).catch'));
    $assert(str_contains($template, 'new BX.KK.Korsac.ConfiguratorRenderer'));
    $assert(!str_contains($component, 'Configurator.get') && !str_contains($component, 'Cart.add'));
});

$test('module file install owns only the configurator component destination', static function () use ($assert): void {
    $installer = (string)file_get_contents(dirname(__DIR__, 2) . '/install/index.php');
    $assert(str_contains($installer, "CopyDirFiles(__DIR__ . '/components', \$_SERVER['DOCUMENT_ROOT'] . '/local/components', true, true)"));
    $assert(str_contains($installer, "DeleteDirFilesEx('/local/components/kk/korsac.configurator')"));
    $assert(!str_contains($installer, "DeleteDirFilesEx('/local/components/kk')"));
    $assert(str_contains($installer, "CopyDirFiles(__DIR__ . '/js'"));
    $assert(str_contains($installer, "CopyDirFiles(__DIR__ . '/admin'"));
});
