<?php

declare(strict_types=1);

use KK\Korsac\Admin\DecimalParser;
use KK\Korsac\Admin\PricingAdminGatewayInterface;
use KK\Korsac\Admin\PricingConfigurationAdminService;
use KK\Korsac\Pricing\PricingConfiguration;

$adminGateway = static function (): PricingAdminGatewayInterface {
    return new class implements PricingAdminGatewayInterface {
        public array $options = [];
        public array $writes = [];
        public function catalogExists(int $iblockId): bool { return $iblockId === 2; }
        public function priceTypeExists(int $priceTypeId): bool { return in_array($priceTypeId, [1, 2], true); }
        public function readOption(string $key): ?string { return $this->options[$key] ?? null; }
        public function writeOption(string $key, string $value): void { $this->writes[] = ['set', $key, $value]; $this->options[$key] = $value; }
        public function removeOption(string $key): void { $this->writes[] = ['remove', $key]; unset($this->options[$key]); }
        public function catalogs(): array { return [2 => 'Каталог товаров']; }
        public function priceTypes(): array { return [1 => 'BASE', 2 => 'Розничная']; }
    };
};

$test('admin decimal parser converts percentage without floats', static function () use ($assert): void {
    foreach (['20'=>2000, '20.00'=>2000, '12.5'=>1250, '12,34'=>1234, '0'=>0] as $input => $expected) {
        $assert(DecimalParser::toBasisPoints((string)$input) === $expected);
    }
    foreach (['-1', '1.234'] as $invalid) {
        try { DecimalParser::toBasisPoints($invalid); } catch (InvalidArgumentException) { continue; }
        throw new RuntimeException("Invalid percentage accepted: {$invalid}");
    }
});

$test('admin decimal parser converts RUB without floats', static function () use ($assert): void {
    foreach (['1000'=>100000, '1000.50'=>100050, '1000,50'=>100050] as $input => $expected) {
        $assert(DecimalParser::toMinorUnits((string)$input) === $expected);
    }
    try { DecimalParser::toMinorUnits('-0.01'); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Negative fixed adjustment accepted');
});

$test('admin service writes RETAIL and BUSINESS through canonical keys', static function () use ($assert, $adminGateway): void {
    $gateway = $adminGateway();
    (new PricingConfigurationAdminService($gateway))->save(2, [
        'RETAIL'=>['enabled'=>'Y','priceTypeId'=>'2','markupPercent'=>'20.00','fixedRub'=>'0'],
        'BUSINESS'=>['enabled'=>'Y','priceTypeId'=>'1','markupPercent'=>'15','fixedRub'=>'10.50'],
    ]);
    $assert($gateway->options[PricingConfiguration::priceTypeKey(2, 'RETAIL')] === '2');
    $assert($gateway->options[PricingConfiguration::markupKey(2, 2)] === '2000');
    $assert($gateway->options[PricingConfiguration::priceTypeKey(2, 'BUSINESS')] === '1');
    $assert($gateway->options[PricingConfiguration::fixedAdjustmentKey(2, 1)] === '1050');
});

$test('admin service validates every channel before writing', static function () use ($assert, $adminGateway): void {
    $gateway = $adminGateway();
    try {
        (new PricingConfigurationAdminService($gateway))->save(2, [
            'RETAIL'=>['enabled'=>'Y','priceTypeId'=>'2','markupPercent'=>'20','fixedRub'=>'0'],
            'BUSINESS'=>['enabled'=>'Y','priceTypeId'=>'999','markupPercent'=>'15','fixedRub'=>'0'],
        ]);
    } catch (InvalidArgumentException) {
        $assert($gateway->writes === []);
        return;
    }
    throw new RuntimeException('Invalid price type accepted');
});

$test('unconfiguring shared channel retains shared policy', static function () use ($assert, $adminGateway): void {
    $gateway = $adminGateway();
    $gateway->options = [
        PricingConfiguration::priceTypeKey(2, 'RETAIL')=>'2', PricingConfiguration::priceTypeKey(2, 'BUSINESS')=>'2',
        PricingConfiguration::markupKey(2, 2)=>'2000', PricingConfiguration::fixedAdjustmentKey(2, 2)=>'0',
    ];
    (new PricingConfigurationAdminService($gateway))->save(2, [
        'RETAIL'=>['enabled'=>'Y','priceTypeId'=>'2','markupPercent'=>'20','fixedRub'=>'0'],
        'BUSINESS'=>['enabled'=>'N'],
    ]);
    $assert(!isset($gateway->options[PricingConfiguration::priceTypeKey(2, 'BUSINESS')]));
    $assert($gateway->options[PricingConfiguration::markupKey(2, 2)] === '2000');
    $assert($gateway->options[PricingConfiguration::fixedAdjustmentKey(2, 2)] === '0');
});
