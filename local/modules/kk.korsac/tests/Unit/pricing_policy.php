<?php

declare(strict_types=1);

$test('pricing mode registry classifies every canonical group', static function () use ($assert): void {
    $assert(\KK\Korsac\Pricing\OptionPricingModeRegistry::all() === [
        'CPU'=>'PROCUREMENT','GPU'=>'PROCUREMENT','MB'=>'PROCUREMENT','RAM'=>'PROCUREMENT','SSD'=>'PROCUREMENT','HDD'=>'PROCUREMENT',
        'PSU'=>'PROCUREMENT','COOLER'=>'PROCUREMENT','CASE'=>'PROCUREMENT','OS'=>'RETAIL','SOFTWARE'=>'RETAIL','SERVICE'=>'RETAIL',
    ]);
    try { \KK\Korsac\Pricing\OptionPricingModeRegistry::mode('MONITOR'); } catch (\InvalidArgumentException) { return; }
    throw new \RuntimeException('Unknown pricing group accepted');
});

$test('retail price calculator applies integer half-up hardware markup only', static function () use ($assert): void {
    $calculator = new \KK\Korsac\Pricing\RetailPriceCalculator();
    $zero = new \KK\Korsac\Pricing\Policy\PricingPolicy(0, 0);
    $twenty = new \KK\Korsac\Pricing\Policy\PricingPolicy(2000, 0);
    $half = new \KK\Korsac\Pricing\Policy\PricingPolicy(5000, 0);
    $assert($calculator->calculate('CPU', 10000, $zero) === 10000);
    $assert($calculator->calculate('CPU', 10000, $twenty) === 12000);
    $assert($calculator->calculate('CPU', 1, $half) === 2);
    $assert($calculator->calculate('CPU', 0, $twenty) === 0);
    foreach (['OS','SOFTWARE','SERVICE'] as $group) { $assert($calculator->calculate($group, 10000, $twenty) === 10000); }
    $assert($calculator->calculate('CPU', 1000000000000, $twenty) === 1200000000000);
    try { $calculator->calculate('CPU', PHP_INT_MAX, $twenty); } catch (\KK\Korsac\Pricing\ConfigurationPricingException $error) { $assert($error->diagnostic()['code'] === 'price_overflow'); return; }
    throw new \RuntimeException('Retail price overflow accepted');
});

$test('pricing policy rejects negative values', static function () use ($assert): void {
    foreach ([[-1,0],[0,-1]] as [$markup,$fixed]) {
        try { new \KK\Korsac\Pricing\Policy\PricingPolicy($markup, $fixed); }
        catch (\KK\Korsac\Pricing\ConfigurationPricingException $error) { $assert($error->diagnostic()['code'] === 'invalid_pricing_policy'); continue; }
        throw new \RuntimeException('Invalid policy accepted');
    }
});

$test('retail option provider transforms hardware and preserves direct retail', static function () use ($assert): void {
    $raw = new class implements \KK\Korsac\Pricing\OptionPriceProviderInterface {
        public int $calls = 0;
        public function getPriceMinor(string $group, string $xmlId): int { ++$this->calls; return 10000; }
    };
    $provider = new \KK\Korsac\Pricing\RetailOptionPriceProvider($raw, new \KK\Korsac\Pricing\Policy\PricingPolicy(2000, 0));
    $assert($provider->getPriceMinor('CPU','A') === 12000);
    $assert($provider->getPriceMinor('SOFTWARE','A') === 10000);
    $assert($provider->getPriceMinor('CPU','A') === 12000 && $raw->calls === 2);
});

$test('default catalog price includes marked-up hardware direct-retail OS and fixed adjustment once', static function () use ($assert): void {
    $configuration = \KK\Korsac\Catalog\ProductConfiguration::fromPropertyValues([
        'KK_CPU_DEFAULT'=>['CPU_A'], 'KK_OS_DEFAULT'=>['OS_A'], 'KK_HDD_OPTIONS'=>['HDD_A'],
        'KK_SOFTWARE_MULTI_OPTIONS'=>['OFFICE'], 'KK_SERVICE_MULTI_OPTIONS'=>['SETUP'],
    ], static fn(string $group,string $id): array => ['UF_XML_ID'=>$id,'UF_ACTIVE'=>1]);
    $raw = new class implements \KK\Korsac\Pricing\OptionPriceProviderInterface {
        public function getPriceMinor(string $group,string $xmlId): int { return $group === 'CPU' ? 100000 : 40000; }
    };
    $policy = new \KK\Korsac\Pricing\Policy\PricingPolicy(2000, 30000);
    $retail = new \KK\Korsac\Pricing\RetailOptionPriceProvider($raw, $policy);
    $result = (new \KK\Korsac\Pricing\DefaultCatalogPriceCalculator($retail))->calculate($configuration, $policy)->toArray();
    $assert($result['componentsRetailMinor'] === 160000 && $result['systemFixedAdjustmentMinor'] === 30000 && $result['totalMinor'] === 190000);
    $assert($result['groups']['CPU']['retailPriceMinor'] === 120000 && $result['groups']['OS']['retailPriceMinor'] === 40000);
    $assert($result['groups']['HDD']['retailPriceMinor'] === 0 && $result['groups']['SOFTWARE']['included'] === false);
});

$test('pricing configuration keys support RETAIL and future BUSINESS without BASE fallback', static function () use ($assert): void {
    $keys = \KK\Korsac\Pricing\Policy\PricingConfigurationKeys::class;
    $assert($keys::priceType(2, 'RETAIL') === 'pricing_price_type_2_RETAIL');
    $assert($keys::priceType(2, 'BUSINESS') === 'pricing_price_type_2_BUSINESS');
    $assert($keys::policy(2, 1) === 'pricing_policy_2_1');
});

$test('configured resolver maps RETAIL and BUSINESS explicitly and has no fallback', static function () use ($assert): void {
    $store = new class implements \KK\Korsac\Pricing\Policy\PricingSettingsStoreInterface {
        public array $values = ['pricing_price_type_2_RETAIL'=>'1','pricing_price_type_2_BUSINESS'=>'2'];
        public function get(string $key): string { return $this->values[$key] ?? ''; }
        public function set(string $key,string $value): void { $this->values[$key] = $value; }
    };
    $resolver = new \KK\Korsac\Pricing\Policy\ConfiguredCatalogPriceTypeResolver($store);
    $assert($resolver->resolve(2, 'RETAIL') === 1 && $resolver->resolve(2, 'BUSINESS') === 2);
    try { $resolver->resolve(3, 'RETAIL'); } catch (\KK\Korsac\Configurator\ConfiguratorException $error) { $assert($error->diagnostic()['code'] === 'catalog_price_type_not_configured'); return; }
    throw new \RuntimeException('Missing price type mapping fell back');
});

$test('policy writer and provider use iblock plus price type keys', static function () use ($assert): void {
    $store = new class implements \KK\Korsac\Pricing\Policy\PricingSettingsStoreInterface {
        public array $values = [];
        public function get(string $key): string { return $this->values[$key] ?? ''; }
        public function set(string $key,string $value): void { $this->values[$key] = $value; }
    };
    $policy = new \KK\Korsac\Pricing\Policy\PricingPolicy(2000, 30000);
    (new \KK\Korsac\Pricing\Policy\PricingConfigurationWriter($store))->configure(2, 'RETAIL', 7, $policy);
    $assert((new \KK\Korsac\Pricing\Policy\ConfiguredCatalogPriceTypeResolver($store))->resolve(2, 'RETAIL') === 7);
    $assert((new \KK\Korsac\Pricing\Policy\BitrixPricingPolicyProvider($store))->get(2, 7) == $policy);
    try { (new \KK\Korsac\Pricing\Policy\BitrixPricingPolicyProvider($store))->get(2, 8); }
    catch (\KK\Korsac\Configurator\ConfiguratorException $error) { $assert($error->diagnostic()['code'] === 'pricing_policy_not_configured'); return; }
    throw new \RuntimeException('Missing policy accepted');
});
