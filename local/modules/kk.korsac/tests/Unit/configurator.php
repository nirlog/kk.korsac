<?php

declare(strict_types=1);

$configuratorFixture = static function (): array {
    $gateway = new class implements \KK\Korsac\Catalog\CatalogPropertyGatewayInterface {
        public array $propertyValues = [
            'KK_CPU_DEFAULT' => ['CPU_A'], 'KK_CPU_OPTIONS' => ['CPU_B'],
            'KK_RAM_DEFAULT' => ['RAM_32'], 'KK_RAM_OPTIONS' => ['RAM_64', 'RAM_16'],
            'KK_HDD_OPTIONS' => ['HDD_2TB'], 'KK_SOFTWARE_MULTI_OPTIONS' => ['OFFICE'],
        ];
        public function iblockExists(int $iblockId): bool { return $iblockId === 2; }
        public function productExists(int $iblockId, int $productId): bool { return $iblockId === 2 && $productId === 4; }
        public function values(int $iblockId, int $productId, string $code): array { return $this->propertyValues[$code] ?? []; }
        public function enumValues(int $iblockId, int $productId, string $code): array { return $this->propertyValues[$code] ?? []; }
        public function enums(int $propertyId): array { foreach ($this->properties ?? [] as $property) { if (($property['ID'] ?? null) === $propertyId) return array_map(static fn(array $v): array => ['XML_ID'=>$v['XML_ID'],'VALUE'=>$v['VALUE'],'DEF'=>$v['DEF']], $property['VALUES'] ?? []); } return []; }
        public function find(int $iblockId, string $code): ?array { return null; }
        public function create(int $iblockId, array $property): int { throw new \LogicException('read only'); }
    };
    $rows = [
        'CPU_A' => ['UF_ACTIVE'=>1,'UF_PRICE'=>'100.00','UF_PUBLIC_NAME'=>'Public CPU','UF_NAME'=>'Internal CPU','UF_DESCRIPTION'=>'Fast'],
        'CPU_B' => ['UF_ACTIVE'=>1,'UF_PRICE'=>'150.00','UF_PUBLIC_NAME'=>'','UF_NAME'=>'Fallback CPU','UF_DESCRIPTION'=>''],
        'RAM_32' => ['UF_ACTIVE'=>1,'UF_PRICE'=>'200.00','UF_PUBLIC_NAME'=>'RAM 32','UF_NAME'=>'internal','UF_DESCRIPTION'=>null],
        'RAM_64' => ['UF_ACTIVE'=>1,'UF_PRICE'=>'300.00','UF_PUBLIC_NAME'=>'RAM 64','UF_NAME'=>'internal','UF_DESCRIPTION'=>null],
        'RAM_16' => ['UF_ACTIVE'=>1,'UF_PRICE'=>'100.00','UF_PUBLIC_NAME'=>'RAM 16','UF_NAME'=>'internal','UF_DESCRIPTION'=>null],
        'HDD_2TB' => ['UF_ACTIVE'=>1,'UF_PRICE'=>'16000.00','UF_PUBLIC_NAME'=>'2 TB','UF_NAME'=>'internal','UF_DESCRIPTION'=>null],
        'OFFICE' => ['UF_ACTIVE'=>1,'UF_PRICE'=>'300.00','UF_PUBLIC_NAME'=>'Office','UF_NAME'=>'internal','UF_DESCRIPTION'=>null],
    ];
    $repository = new class($rows) extends \KK\Korsac\Repository\OptionRepository {
        public function __construct(private array $rows) {}
        public function findByTypeAndXmlId(string $type, string $xmlId): ?array { return $this->rows[$xmlId] ?? null; }
    };
    $base = new class implements \KK\Korsac\Configurator\CatalogPriceProviderInterface {
        public function get(int $productId, int $priceTypeId): \KK\Korsac\Configurator\CatalogPrice { return new \KK\Korsac\Configurator\CatalogPrice($priceTypeId, 'RUB', 3099000); }
    };
    $resolver = new class implements \KK\Korsac\Pricing\CatalogPriceTypeResolverInterface {
        public function resolve(int $iblockId, string $channel): int { return 1; }
    };
    $policies = new class implements \KK\Korsac\Pricing\PricingPolicyProviderInterface {
        public function get(int $iblockId, int $priceTypeId): \KK\Korsac\Pricing\PricingPolicy { return new \KK\Korsac\Pricing\PricingPolicy(2000, 0); }
    };
    return [new \KK\Korsac\Configurator\ConfiguratorService(
        new \KK\Korsac\Catalog\ProductConfigurationRepository($gateway, $repository),
        $base,
        new \KK\Korsac\Configurator\HlOptionViewProvider($repository),
        new \KK\Korsac\Pricing\HlOptionPriceProvider($repository),
        $resolver,
        $policies,
        new \KK\Korsac\Catalog\ProductPresentationRepository($gateway),
    ), $gateway];
};

$test('configurator GET projects all groups metadata ordering and calculator deltas safely', static function () use ($assert, $configuratorFixture): void {
    [$service] = $configuratorFixture();
    $result = $service->get(2, 4);
    $assert(count($result['groups']) === 12);
    $assert(array_column($result['groups']['RAM']['choices'], 'xmlId') === ['RAM_32','RAM_64','RAM_16']);
    $assert($result['groups']['HDD']['allowNull'] === true && $result['groups']['SOFTWARE']['default'] === []);
    $assert($result['groups']['CPU']['choices'][0] === ['xmlId'=>'CPU_A','name'=>'Public CPU','description'=>'Fast','image'=>null,'deltaMinor'=>0]);
    $assert($result['groups']['CPU']['choices'][1]['name'] === 'Fallback CPU' && $result['groups']['CPU']['choices'][1]['description'] === null);
    $assert($result['groups']['RAM']['choices'][1]['deltaMinor'] === 12000);
    $assert($result['groups']['RAM']['choices'][2]['deltaMinor'] === -12000);
    $assert($result['groups']['HDD']['choices'][0]['deltaMinor'] === 1920000);
    $assert($result['groups']['SOFTWARE']['choices'][0]['deltaMinor'] === 30000);
    $json = json_encode($result);
    foreach (['UF_PRICE','defaultPriceMinor','selectedPriceMinor','priceMinor'] as $forbidden) { $assert(!str_contains($json, $forbidden), "Leaked {$forbidden}"); }
});

$test('configurator calculate normalizes selection and exposes only public group deltas', static function () use ($assert, $configuratorFixture): void {
    [$service] = $configuratorFixture();
    $result = $service->calculate(2, 4, ['HDD'=>'HDD_2TB','SOFTWARE'=>['OFFICE']]);
    $assert($result['selection']['CPU'] === 'CPU_A' && $result['selection']['HDD'] === 'HDD_2TB' && $result['selection']['SERVICE'] === []);
    $assert($result['price']['configurationDeltaMinor'] === 1950000 && $result['price']['finalPriceMinor'] === 5049000);
    $assert(count($result['price']['groupDeltas']) === 12 && $result['price']['groupDeltas']['HDD'] === 1920000);
    $json = json_encode($result);
    foreach (['defaultPriceMinor','selectedPriceMinor','priceMinor'] as $forbidden) { $assert(!str_contains($json, $forbidden), "Leaked {$forbidden}"); }
});

$test('catalog price provider uses explicit type and rejects missing or non-RUB rows', static function () use ($assert): void {
    $gateway = new class implements \KK\Korsac\Configurator\CatalogPriceGatewayInterface {
        public ?array $row = ['priceTypeId'=>1,'price'=>'30990.00000000','currency'=>'RUB'];
        public array $calls = [];
        public function findPrice(int $productId, int $priceTypeId): ?array { $this->calls[] = [$productId, $priceTypeId]; return $this->row; }
    };
    $provider = new \KK\Korsac\Configurator\BitrixCatalogPriceProvider($gateway);
    $price = $provider->get(4, 1);
    $assert($price->priceTypeId === 1 && $price->currency === 'RUB' && $price->priceMinor === 3099000);
    $assert($gateway->calls === [[4, 1]]);
    foreach ([[null,'catalog_price_not_found'], [['priceTypeId'=>1,'price'=>'1.00','currency'=>'USD'],'unsupported_catalog_currency']] as [$row,$code]) {
        $gateway->row = $row;
        try { $provider->get(4, 1); } catch (\KK\Korsac\Configurator\ConfiguratorException $error) { $assert($error->diagnostic()['code'] === $code); continue; }
        throw new \RuntimeException("Provider accepted {$code}");
    }
});

$test('controller error projection keeps domain diagnostics and hides throwable details', static function () use ($assert): void {
    $mapper = new \KK\Korsac\Configurator\ConfiguratorErrorMapper();
    $assert($mapper->map(['code'=>'option_not_allowed','group'=>'RAM','xmlId'=>'BAD']) === ['code'=>'option_not_allowed','message'=>'Selected option is not allowed','customData'=>['group'=>'RAM','xmlId'=>'BAD']]);
    $assert($mapper->map(['code'=>'catalog_price_type_not_configured','exception'=>'ConfigurationPricingException']) === ['code'=>'catalog_price_type_not_configured','message'=>'Catalog price type is not configured','customData'=>[]]);
    $assert($mapper->map(['code'=>'pricing_policy_not_configured','trace'=>'secret']) === ['code'=>'pricing_policy_not_configured','message'=>'Pricing policy is not configured','customData'=>[]]);
    $assert($mapper->map(['code'=>'secret_sql_error','path'=>'/secret','message'=>'SQL failed']) === ['code'=>'internal_error','message'=>'Internal error','customData'=>[]]);
});
