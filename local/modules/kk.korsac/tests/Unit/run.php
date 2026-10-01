<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\Migration\SimplifyHlSchema;
use KK\Korsac\Install\Migration\PricePrecision;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\MigrationRunner;
use KK\Korsac\Install\MigrationStoreInterface;
use KK\Korsac\Install\SchemaComparator;
use KK\Korsac\Install\SchemaDefinition;
use KK\Korsac\Install\SchemaGatewayInterface;
use KK\Korsac\Install\SchemaInstaller;
use KK\Korsac\Install\SchemaMigrationService;
use KK\Korsac\Install\SqlIndexBuilder;
use KK\Korsac\Repository\OptionTypeRegistry;
use KK\Korsac\Catalog\CatalogPropertyGatewayInterface;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\CatalogPropertyInstaller;
use KK\Korsac\Catalog\CatalogPropertySchema;
use KK\Korsac\Catalog\CatalogPropertySelfCheck;
use KK\Korsac\Catalog\ProductConfiguration;
use KK\Korsac\Catalog\ProductConfigurationException;
use KK\Korsac\Catalog\PropertyCodeParser;
use KK\Korsac\Pricing\ConfigurationPriceCalculator;
use KK\Korsac\Pricing\ConfigurationPricingException;
use KK\Korsac\Pricing\ConfigurationSelection;
use KK\Korsac\Pricing\DefaultConfigurationCostCalculator;
use KK\Korsac\Pricing\HlOptionPriceProvider;
use KK\Korsac\Pricing\OptionPriceProviderInterface;
use KK\Korsac\Pricing\PriceNormalizer;
use KK\Korsac\Repository\OptionRepository;

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void { $tests[$name] = $callback; };
$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$fakeGateway = static function (): SchemaGatewayInterface {
    return new class implements SchemaGatewayInterface {
        public array $blocks = [], $fields = [], $indexes = [], $rowCounts = [], $deleted = [], $fieldUpdates = [];
        public int $writes = 0;
        public function getBlock(string $name): ?array { return $this->blocks[$name] ?? null; }
        public function getBlockByTable(string $tableName): ?array { foreach ($this->blocks as $block) { if ($block['TABLE_NAME'] === $tableName) return $block; } return null; }
        public function createBlock(string $name, string $tableName): array { ++$this->writes; return $this->blocks[$name] = ['ID' => count($this->blocks) + 1, 'NAME' => $name, 'TABLE_NAME' => $tableName]; }
        public function getFields(int $blockId): array { return $this->fields[$blockId] ?? []; }
        public function createField(int $blockId, array $field): void { ++$this->writes; $settings = []; if ($field['type'] === 'string' && $field['length'] !== null) $settings['MAX_LENGTH'] = $field['length']; if ($field['type'] === 'double' && $field['precision'] !== null) $settings['PRECISION'] = $field['precision']; $this->fields[$blockId][$field['name']] = ['ID' => $blockId * 100 + count($this->fields[$blockId] ?? []), 'USER_TYPE_ID' => $field['type'], 'MULTIPLE' => $field['multiple'] ? 'Y' : 'N', 'MANDATORY' => $field['required'] ? 'Y' : 'N', 'SETTINGS' => $settings]; }
        public function updateFieldSettings(int $fieldId, array $settings): void { ++$this->writes; $this->fieldUpdates[] = $fieldId; foreach ($this->fields as &$fields) foreach ($fields as &$field) if (($field['ID'] ?? null) === $fieldId) $field['SETTINGS'] = $settings; }
        public function getIndexes(string $tableName): array { return $this->indexes[$tableName] ?? []; }
        public function findDuplicateRows(string $tableName, array $columns): array { return []; }
        public function createIndex(string $tableName, string $name, array $columns, bool $unique): void { ++$this->writes; $this->indexes[$tableName][$name] = ['columns' => $columns, 'unique' => $unique]; }
        public function rows(string $blockName, array $select = ['*']): array { return []; }
        public function countRows(string $blockName): int { return $this->rowCounts[$blockName] ?? 0; }
        public function deleteBlock(string $blockName): void { if (isset($this->blocks[$blockName])) ++$this->writes; $this->deleted[] = $blockName; unset($this->blocks[$blockName]); }
    };
};
$fakeStore = static function (array $applied = []): MigrationStoreInterface {
    return new class($applied) implements MigrationStoreInterface {
        public array $ids = [];
        public function __construct(array $applied) { foreach ($applied as $id) $this->ids[$id] = true; }
        public function has(string $migrationId): bool { return isset($this->ids[$migrationId]); }
        public function markApplied(string $migrationId): void { $this->ids[$migrationId] = true; }
    };
};
$fakeCatalogGateway = static function (): CatalogPropertyGatewayInterface {
    return new class implements CatalogPropertyGatewayInterface {
        public array $properties = [], $propertyValues = [];
        public int $writes = 0;
        public function iblockExists(int $iblockId): bool { return $iblockId === 123; }
        public function find(int $iblockId, string $code): ?array { return $this->properties[$code] ?? null; }
        public function create(int $iblockId, array $property): int { ++$this->writes; $this->properties[$property['CODE']] = $property; return $this->writes; }
        public function values(int $iblockId, int $productId, string $code): array { return $this->propertyValues[$code] ?? []; }
        public function productExists(int $iblockId, int $productId): bool { return $iblockId === 123 && $productId === 456; }
    };
};

$test('catalog property parser recognizes canonical codes only', static function () use ($assert): void {
    $parser = new PropertyCodeParser();
    $assert($parser->parse('KK_RAM_DEFAULT') === ['group'=>'RAM','role'=>'DEFAULT','mode'=>'single']);
    $assert($parser->parse('KK_RAM_OPTIONS') === ['group'=>'RAM','role'=>'OPTIONS','mode'=>'single']);
    $assert($parser->parse('KK_SERVICE_MULTI_OPTIONS') === ['group'=>'SERVICE','role'=>'MULTI_OPTIONS','mode'=>'multiple']);
    foreach (['KK_SERVICE_MULTIOPTIONS', 'KK_UNKNOWN_OPTIONS', 'KK_SERVICE_OPTIONS', 'KK_RAM_MULTI_OPTIONS'] as $code) {
        try { $parser->parse($code); } catch (InvalidArgumentException) { continue; }
        throw new RuntimeException("Invalid code accepted: {$code}");
    }
});
$test('catalog schema generates twenty-two directory properties', static function () use ($assert): void {
    $groups = CatalogPropertySchema::groups(); $properties = CatalogPropertySchema::properties();
    $assert(count($properties) === 22);
    $assert(count(array_filter($groups, static fn(array $group): bool => $group['mode'] === 'single')) === 10);
    $assert(count(array_filter($groups, static fn(array $group): bool => $group['mode'] === 'multiple')) === 2);
    $assert($properties['KK_RAM_DEFAULT']['USER_TYPE_SETTINGS']['TABLE_NAME'] === 'b_hlbd_korsac_ram');
    $assert($properties['KK_SERVICE_MULTI_OPTIONS']['USER_TYPE_SETTINGS']['TABLE_NAME'] === 'b_hlbd_korsac_service');
    $assert($properties['KK_RAM_DEFAULT']['MULTIPLE'] === 'N');
    $assert($properties['KK_RAM_OPTIONS']['MULTIPLE'] === 'Y');
    $assert($properties['KK_SERVICE_MULTI_OPTIONS']['MULTIPLE'] === 'Y');
    foreach ($properties as $property) { $assert($property['PROPERTY_TYPE'] === 'S'); $assert($property['USER_TYPE'] === 'directory'); $assert($property['IS_REQUIRED'] === 'N'); }
});
$test('Bitrix property reads use stable multiple value ordering', static function () use ($assert): void {
    $assert(BitrixCatalogPropertyGateway::PROPERTY_VALUE_ORDER === ['sort'=>'asc', 'id'=>'asc', 'value_id'=>'asc']);
    $source = file_get_contents(dirname(__DIR__, 2) . '/lib/Catalog/BitrixCatalogPropertyGateway.php');
    $assert(str_contains((string)$source, 'GetProperty($iblockId, $productId, self::PROPERTY_VALUE_ORDER'));
});
$test('catalog property installer is idempotent and rejects conflicts', static function () use ($assert, $fakeCatalogGateway): void {
    $gateway = $fakeCatalogGateway(); $installer = new CatalogPropertyInstaller($gateway);
    $assert($installer->installWithResult(123) === ['created'=>22, 'existing'=>0]);
    $writes = $gateway->writes;
    $assert($installer->installWithResult(123) === ['created'=>0, 'existing'=>22]);
    $assert($gateway->writes === $writes);
    foreach ([
        ['MULTIPLE', 'N'], ['USER_TYPE', 'String'], ['IS_REQUIRED', 'Y'], ['USER_TYPE_SETTINGS', ['TABLE_NAME'=>'b_wrong']],
    ] as [$field, $value]) {
        $broken = $fakeCatalogGateway(); $broken->properties = CatalogPropertySchema::properties(); $broken->properties['KK_RAM_OPTIONS'][$field] = $value;
        try { (new CatalogPropertyInstaller($broken))->install(123); } catch (RuntimeException) { continue; }
        throw new RuntimeException("Installer accepted incompatible {$field}");
    }
});
$test('catalog property self-check emits actionable mismatches', static function () use ($assert, $fakeCatalogGateway): void {
    $gateway = $fakeCatalogGateway(); $gateway->properties = CatalogPropertySchema::properties();
    unset($gateway->properties['KK_CPU_DEFAULT']);
    $gateway->properties['KK_RAM_OPTIONS']['MULTIPLE'] = 'N';
    $gateway->properties['KK_GPU_DEFAULT']['USER_TYPE'] = '';
    $gateway->properties['KK_HDD_DEFAULT']['IS_REQUIRED'] = 'Y';
    $gateway->properties['KK_SERVICE_MULTI_OPTIONS']['USER_TYPE_SETTINGS']['TABLE_NAME'] = 'b_wrong';
    $errors = (new CatalogPropertySelfCheck($gateway))->run(123)['errors'];
    foreach (['missing_property','property_multiple_mismatch','property_user_type_mismatch','property_required_mismatch','property_directory_mismatch'] as $code) $assert(in_array($code, array_column($errors, 'code'), true), $code);
    $required = array_values(array_filter($errors, static fn(array $error): bool => $error['code'] === 'property_required_mismatch'));
    $assert($required === [['code'=>'property_required_mismatch','property'=>'KK_HDD_DEFAULT','expected'=>'N','actual'=>'Y']]);
});
$test('product configuration supports optional defaults and preserves order', static function () use ($assert): void {
    $values = ['KK_CPU_DEFAULT'=>['CPU_A'], 'KK_HDD_OPTIONS'=>['HDD_4','HDD_2'], 'KK_SOFTWARE_MULTI_OPTIONS'=>['SW_B','SW_A']];
    $configuration = ProductConfiguration::fromPropertyValues($values, static fn(string $group,string $id): array => ['UF_XML_ID'=>$id,'UF_ACTIVE'=>1])->toArray();
    $assert($configuration['CPU'] === ['mode'=>'single','default'=>'CPU_A','options'=>[]]);
    $assert($configuration['HDD'] === ['mode'=>'single','default'=>null,'options'=>['HDD_4','HDD_2']]);
    $assert($configuration['SOFTWARE']['options'] === ['SW_B','SW_A']);
});
$test('product configuration reports duplicate missing and inactive options', static function () use ($assert): void {
    $cases = [
        [['KK_RAM_DEFAULT'=>['RAM_A'], 'KK_RAM_OPTIONS'=>['RAM_A']], 'default_duplicated_in_options', static fn()=>['UF_ACTIVE'=>1]],
        [['KK_RAM_OPTIONS'=>['RAM_A','RAM_A']], 'duplicate_option', static fn()=>['UF_ACTIVE'=>1]],
        [['KK_RAM_OPTIONS'=>['RAM_UNKNOWN']], 'missing_option', static fn()=>null],
        [['KK_RAM_OPTIONS'=>['RAM_OLD']], 'inactive_option', static fn()=>['UF_ACTIVE'=>0]],
    ];
    foreach ($cases as [$values, $code, $resolver]) {
        try { ProductConfiguration::fromPropertyValues($values, $resolver); }
        catch (ProductConfigurationException $error) { $assert($error->diagnostic()['code'] === $code); continue; }
        throw new RuntimeException("Configuration error not detected: {$code}");
    }
});

$test('module bootstrap and CLI entrypoints are portable', static function () use ($assert): void {
    $namespaces = require __DIR__ . '/fixtures/load_module_include.php';
    $assert(realpath($namespaces['KK\\Korsac'] ?? '') === realpath(dirname(__DIR__, 2) . '/lib'));
    foreach (['tools/schema.php', 'tools/catalog.php', 'tests/Integration/smoke.php', 'tests/Integration/install_smoke.php', 'tests/Integration/acceptance_smoke.php', 'tests/Integration/schema_v02_migration_smoke.php', 'tests/Integration/catalog_properties_smoke.php', 'tests/Integration/product_configuration_smoke.php', 'tests/Integration/configuration_pricing_smoke.php', 'tests/Integration/default_configuration_cost_smoke.php'] as $file) {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $assert($source !== false, "Cannot read {$file}");
        $syncPosition = strpos($source, "\$_SERVER['DOCUMENT_ROOT'] = \$documentRoot;");
        $prologPosition = strpos($source, "require \$documentRoot . '/bitrix/modules/main/include/prolog_before.php';");
        $assert($syncPosition !== false, "{$file} does not synchronize DOCUMENT_ROOT");
        $assert($prologPosition !== false, "{$file} does not load the Bitrix prolog");
        $assert($syncPosition < $prologPosition, "{$file} synchronizes DOCUMENT_ROOT too late");
    }
});
$test('target schema is twelve identical option directories', static function () use ($assert): void {
    $entities = SchemaDefinition::entities();
    $assert(count($entities) === 12);
    $assert(array_keys(SchemaDefinition::OPTION_TYPES) === ['CPU','GPU','MB','RAM','SSD','HDD','PSU','COOLER','CASE','OS','SOFTWARE','SERVICE']);
    $expectedFields = ['UF_XML_ID','UF_NAME','UF_PUBLIC_NAME','UF_ACTIVE','UF_SORT','UF_PRICE','UF_PRICE_UPDATED_AT','UF_DESCRIPTION','UF_CREATED_AT','UF_UPDATED_AT'];
    foreach ($entities as $entity) { $assert(array_keys($entity['fields']) === $expectedFields); $assert(count($entity['indexes']) === 2); }
    foreach (['KorsacHdd','KorsacOs','KorsacSoftware','KorsacService'] as $name) $assert(isset($entities[$name]));
    foreach (['KorsacPhysicalSku','KorsacSupplierOffer','KorsacValidatedBuild'] as $name) $assert(!isset($entities[$name]));
    $assert($entities['KorsacMotherboard']['table'] === 'b_hlbd_korsac_mb');
    $assert($entities['KorsacCpu']['fields']['UF_PRICE']['precision'] === 2);
});
$test('double field comparator requires expected precision', static function () use ($assert): void {
    $field = SchemaDefinition::entities()['KorsacCpu']['fields']['UF_PRICE'];
    $base = ['USER_TYPE_ID'=>'double', 'MULTIPLE'=>'N', 'MANDATORY'=>'Y'];
    $assert(SchemaComparator::fieldIsCompatible($base + ['SETTINGS'=>['PRECISION'=>2]], $field));
    $assert(!SchemaComparator::fieldIsCompatible($base + ['SETTINGS'=>['PRECISION'=>0]], $field));
    $assert(!SchemaComparator::fieldIsCompatible($base + ['SETTINGS'=>[]], $field));
});
$test('option registry maps all types and rejects unknown values', static function () use ($assert): void {
    foreach (SchemaDefinition::OPTION_TYPES as $type => $block) $assert(OptionTypeRegistry::blockName(strtolower($type)) === $block);
    try { OptionTypeRegistry::blockName('unknown'); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Unknown option type accepted');
});
$test('option repository is coupled only to option registry', static function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/lib/Repository/OptionRepository.php');
    $assert(str_contains((string)$source, 'OptionTypeRegistry::blockName($type)'));
    $assert(!str_contains((string)$source, 'ComponentClass'));
});
$test('self-check detects empty duplicate XML IDs and negative prices', static function () use ($assert): void {
    $errors = SchemaSelfCheck::analyzeData(['KorsacRam' => [['UF_XML_ID'=>'','UF_PRICE'=>0], ['UF_XML_ID'=>'RAM_A','UF_PRICE'=>-1], ['UF_XML_ID'=>'RAM_A','UF_PRICE'=>2]]]);
    foreach (['empty_xml_id','negative_price','duplicate_xml_id'] as $code) $assert(in_array($code, array_column($errors, 'code'), true), $code);
});
$test('migration performs complete preflight before any deletion', static function () use ($assert, $fakeGateway): void {
    $gateway = $fakeGateway(); $gateway->rowCounts['KorsacRamClass'] = 3; $gateway->rowCounts['KorsacPhysicalSku'] = 5;
    try { (new SimplifyHlSchema($gateway, new SchemaInstaller($gateway)))->up(); } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'KorsacRamClass contains 3 rows'));
        $assert(str_contains($e->getMessage(), 'KorsacPhysicalSku contains 5 rows'));
        $assert($gateway->deleted === []); return;
    }
    throw new RuntimeException('Migration was not blocked');
});
$test('fresh install baselines historical 001 and creates only v0.2', static function () use ($assert, $fakeGateway, $fakeStore): void {
    $gateway = $fakeGateway(); $store = $fakeStore();
    $applied = (new SchemaMigrationService($store, $gateway))->migrate();
    $assert($applied === [SchemaMigrationService::V01_MIGRATION_ID, '2026_09_30_002_simplify_hl_schema', '2026_09_30_003_price_precision']);
    $assert(count(array_intersect(array_keys($gateway->blocks), array_keys(SchemaDefinition::entities()))) === 12);
    foreach (SimplifyHlSchema::LEGACY_BLOCKS as $name) $assert(!isset($gateway->blocks[$name]));
});
$test('existing v0.1 with applied 001 transitions through 002', static function () use ($assert, $fakeGateway, $fakeStore): void {
    $gateway = $fakeGateway();
    foreach (SimplifyHlSchema::LEGACY_BLOCKS as $name) $gateway->blocks[$name] = ['ID'=>count($gateway->blocks)+1,'NAME'=>$name,'TABLE_NAME'=>'legacy_' . count($gateway->blocks)];
    $store = $fakeStore([SchemaMigrationService::V01_MIGRATION_ID]);
    $assert((new SchemaMigrationService($store, $gateway))->migrate() === ['2026_09_30_002_simplify_hl_schema', '2026_09_30_003_price_precision']);
    foreach (SimplifyHlSchema::LEGACY_BLOCKS as $name) $assert(!isset($gateway->blocks[$name]));
    foreach (array_keys(SchemaDefinition::entities()) as $name) $assert(isset($gateway->blocks[$name]));
});
$test('price precision migration updates all option fields and is idempotent', static function () use ($assert, $fakeGateway): void {
    $gateway = $fakeGateway();
    (new SchemaInstaller($gateway))->install();
    foreach ($gateway->fields as &$fields) {
        $fields['UF_PRICE']['SETTINGS'] = ['PRECISION'=>0, 'EXISTING_SETTING'=>'preserved'];
    }
    $migration = new PricePrecision($gateway);
    $migration->up();
    $assert(count($gateway->fieldUpdates) === 12);
    foreach ($gateway->fields as $fields) {
        $assert($fields['UF_PRICE']['SETTINGS']['PRECISION'] === 2);
        $assert($fields['UF_PRICE']['SETTINGS']['EXISTING_SETTING'] === 'preserved');
    }
    $writes = $gateway->writes;
    $migration->up();
    $assert($gateway->writes === $writes);
});
$test('legacy data without 001 marker blocks before schema or history writes', static function () use ($assert, $fakeGateway, $fakeStore): void {
    $gateway = $fakeGateway(); $store = $fakeStore();
    $gateway->blocks['KorsacRamClass'] = ['ID'=>1, 'NAME'=>'KorsacRamClass', 'TABLE_NAME'=>'b_hlbd_korsac_ram_class'];
    $gateway->rowCounts['KorsacRamClass'] = 3;
    try { (new SchemaMigrationService($store, $gateway))->migrate(); } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'KorsacRamClass contains 3 rows'));
        $assert($gateway->writes === 0, 'Schema was changed before blocker');
        $assert($store->ids === [], 'Historical baseline was recorded before blocker');
        foreach (array_keys(SchemaDefinition::entities()) as $name) $assert(!isset($gateway->blocks[$name]));
        return;
    }
    throw new RuntimeException('Migration was not blocked');
});
$test('repeated migrate is idempotent', static function () use ($assert, $fakeGateway, $fakeStore): void {
    $gateway = $fakeGateway(); $store = $fakeStore(); $service = new SchemaMigrationService($store, $gateway);
    $service->migrate(); $writes = $gateway->writes;
    $assert($service->migrate() === []);
    $assert($gateway->writes === $writes, 'Repeated migrate performed schema writes');
});
$test('migration runner records successful migration once', static function () use ($assert): void {
    $store = new class implements MigrationStoreInterface { public array $ids=[]; public function has(string $id): bool{return isset($this->ids[$id]);} public function markApplied(string $id):void{$this->ids[$id]=true;} };
    $migration = new class implements MigrationInterface { public int $runs=0; public function id():string{return 'x';} public function up():void{++$this->runs;} };
    $runner = new MigrationRunner($store); $assert($runner->run([$migration]) === ['x']); $assert($runner->run([$migration]) === []); $assert($migration->runs === 1);
});
$test('bounded string indexes retain prefixes and comparison semantics', static function () use ($assert): void {
    $index = SchemaDefinition::entities()['KorsacCpu']['indexes']['ux_korsac_cpu_xml_id'];
    $assert(SqlIndexBuilder::create('b_hlbd_korsac_cpu', 'ux_korsac_cpu_xml_id', $index['columns'], true) === 'CREATE UNIQUE INDEX `ux_korsac_cpu_xml_id` ON `b_hlbd_korsac_cpu` (`UF_XML_ID`(128))');
    $assert(SchemaComparator::indexIsCompatible(['unique'=>1,'columns'=>[['name'=>'uf_xml_id','length'=>'128']]], $index));
    $assert(!SchemaComparator::indexIsCompatible(['unique'=>1,'columns'=>[['name'=>'UF_XML_ID','length'=>64]]], $index));
    $field = SchemaDefinition::entities()['KorsacCpu']['fields']['UF_XML_ID'];
    $assert(SchemaComparator::fieldIsCompatible(['USER_TYPE_ID'=>'string','MULTIPLE'=>'N','MANDATORY'=>'Y','SETTINGS'=>['MAX_LENGTH'=>128]], $field));
});

$pricingConfiguration = static function (): ProductConfiguration {
    return ProductConfiguration::fromPropertyValues([
        'KK_RAM_DEFAULT'=>['RAM_32'], 'KK_RAM_OPTIONS'=>['RAM_64'],
        'KK_GPU_DEFAULT'=>['GPU_80'], 'KK_GPU_OPTIONS'=>['GPU_65'],
        'KK_HDD_OPTIONS'=>['HDD_2TB'],
        'KK_SOFTWARE_MULTI_OPTIONS'=>['SOFTWARE_A','SOFTWARE_B'],
    ], static fn(string $group, string $id): array => ['UF_XML_ID'=>$id, 'UF_ACTIVE'=>1]);
};
$test('default configuration cost sums only single defaults and returns all groups', static function () use ($assert): void {
    $configuration = ProductConfiguration::fromPropertyValues([
        'KK_CPU_DEFAULT'=>['CPU_A'], 'KK_GPU_DEFAULT'=>['GPU_A'],
        'KK_RAM_DEFAULT'=>['RAM_A'], 'KK_RAM_OPTIONS'=>['RAM_64','RAM_96'],
        'KK_HDD_OPTIONS'=>['HDD_2TB'], 'KK_OS_DEFAULT'=>['OS_A'],
        'KK_SOFTWARE_MULTI_OPTIONS'=>['OFFICE','ANTIVIRUS'],
        'KK_SERVICE_MULTI_OPTIONS'=>['SETUP'],
    ], static fn(string $group, string $id): array => ['UF_XML_ID'=>$id, 'UF_ACTIVE'=>1]);
    $provider = new class implements OptionPriceProviderInterface {
        public array $calls = [];
        private array $prices = ['CPU_A'=>100000, 'GPU_A'=>200000, 'RAM_A'=>300000, 'OS_A'=>40000];
        public function getPriceMinor(string $group, string $xmlId): int { $this->calls[] = [$group, $xmlId]; return $this->prices[$xmlId]; }
    };
    $resultObject = (new DefaultConfigurationCostCalculator($provider))->calculate($configuration);
    $result = $resultObject->toArray();
    $assert($result['totalMinor'] === 640000);
    $assert(count($result['groups']) === 12);
    $assert(array_keys($result['groups']) === ['CPU','GPU','MB','RAM','SSD','HDD','PSU','COOLER','CASE','OS','SOFTWARE','SERVICE']);
    $assert($result['groups']['RAM'] === ['mode'=>'single','xmlId'=>'RAM_A','priceMinor'=>300000]);
    $assert($result['groups']['HDD'] === ['mode'=>'single','xmlId'=>null,'priceMinor'=>0]);
    $assert($result['groups']['SOFTWARE'] === ['mode'=>'multiple','included'=>false,'priceMinor'=>0]);
    $assert($provider->calls === [['CPU','CPU_A'],['GPU','GPU_A'],['RAM','RAM_A'],['OS','OS_A']]);
    $assert($resultObject->jsonSerialize() === $result);
});
$test('default configuration cost does not call provider for null defaults or multiple options', static function () use ($assert): void {
    $configuration = ProductConfiguration::fromPropertyValues([
        'KK_RAM_OPTIONS'=>['RAM_64','RAM_96'],
        'KK_SOFTWARE_MULTI_OPTIONS'=>['OFFICE'],
        'KK_SERVICE_MULTI_OPTIONS'=>['SETUP'],
    ], static fn(string $group, string $id): array => ['UF_XML_ID'=>$id, 'UF_ACTIVE'=>1]);
    $provider = new class implements OptionPriceProviderInterface {
        public int $calls = 0;
        public function getPriceMinor(string $group, string $xmlId): int { ++$this->calls; return 1; }
    };
    $result = (new DefaultConfigurationCostCalculator($provider))->calculate($configuration)->toArray();
    $assert($result['totalMinor'] === 0);
    $assert($provider->calls === 0);
});
$test('default configuration cost detects integer overflow', static function () use ($assert): void {
    $configuration = ProductConfiguration::fromPropertyValues([
        'KK_CPU_DEFAULT'=>['CPU_A'], 'KK_GPU_DEFAULT'=>['GPU_A'],
    ], static fn(string $group, string $id): array => ['UF_XML_ID'=>$id, 'UF_ACTIVE'=>1]);
    $provider = new class implements OptionPriceProviderInterface {
        public function getPriceMinor(string $group, string $xmlId): int { return $group === 'CPU' ? PHP_INT_MAX : 1; }
    };
    try { (new DefaultConfigurationCostCalculator($provider))->calculate($configuration); }
    catch (ConfigurationPricingException $error) { $assert($error->diagnostic() === ['code'=>'price_overflow']); return; }
    throw new RuntimeException('Default cost overflow was not detected');
});
$test('price normalizer converts decimal boundary values without float arithmetic downstream', static function () use ($assert): void {
    foreach ([
        ['30990.00000000',3099000], ['1234.56000000',123456], ['1234.50000000',123450],
        ['1234.00000000',123400], ['0.01000000',1], ['1234.56',123456], ['1234.5',123450],
        ['1234',123400], ['+1234.56',123456], ['-1234.56',-123456], [1234,123400],
        [1234.56,123456], [0,0], [0.01,1], ['92233720368547758.07000000',PHP_INT_MAX],
    ] as [$value,$expected]) {
        $assert(PriceNormalizer::toMinor($value) === $expected);
    }
    foreach ([
        null, true, '', '12.345', '1234.56700000', '1234.56010000', '1234.56000001',
        '0.001', '-1.999', '92233720368547758.08000000', 'abc', INF,
    ] as $value) {
        try { PriceNormalizer::toMinor($value); } catch (ConfigurationPricingException $error) { $assert($error->diagnostic()['code'] === 'invalid_option_price'); continue; }
        throw new RuntimeException('Invalid price accepted');
    }
});
$test('configuration selection normalizes defaults and validates whitelist and types', static function () use ($assert, $pricingConfiguration): void {
    $configuration = $pricingConfiguration();
    $default = ConfigurationSelection::fromArray($configuration, [])->toArray();
    $assert($default['RAM'] === 'RAM_32' && $default['HDD'] === null && $default['SOFTWARE'] === []);
    $assert(ConfigurationSelection::fromArray($configuration, ['HDD'=>null])->toArray()['HDD'] === null);
    $cases = [
        [['MONITOR'=>'X'],'unknown_configuration_group'], [['RAM'=>'RAM_128'],'option_not_allowed'],
        [['SOFTWARE'=>['SOFTWARE_X']],'option_not_allowed'], [['RAM'=>['RAM_64']],'invalid_single_selection'],
        [['SOFTWARE'=>'SOFTWARE_A'],'invalid_multiple_selection'], [['SOFTWARE'=>['SOFTWARE_A','SOFTWARE_A']],'duplicate_selected_option'],
        [['RAM'=>null],'null_not_allowed'],
    ];
    foreach ($cases as [$input,$code]) {
        try { ConfigurationSelection::fromArray($configuration, $input); }
        catch (ConfigurationPricingException $error) { $assert($error->diagnostic()['code'] === $code); continue; }
        throw new RuntimeException("Selection {$code} accepted");
    }
});
$test('configuration selection is bound to its product configuration snapshot', static function () use ($assert): void {
    $resolver = static fn(string $group, string $id): array => ['UF_XML_ID'=>$id, 'UF_ACTIVE'=>1];
    $configurationA = ProductConfiguration::fromPropertyValues(['KK_RAM_DEFAULT'=>['RAM_A']], $resolver);
    $equivalentConfigurationA = ProductConfiguration::fromPropertyValues(['KK_RAM_DEFAULT'=>['RAM_A']], $resolver);
    $configurationB = ProductConfiguration::fromPropertyValues(['KK_RAM_DEFAULT'=>['RAM_B']], $resolver);
    $selection = ConfigurationSelection::fromArray($configurationA, []);
    $provider = new class implements OptionPriceProviderInterface {
        public int $calls = 0;
        public function getPriceMinor(string $group,string $xmlId):int { ++$this->calls; return 100; }
    };
    $calculator = new ConfigurationPriceCalculator($provider);
    try { $calculator->calculate($configurationB, $selection, 15000000); }
    catch (ConfigurationPricingException $error) {
        $assert($error->diagnostic() === ['code'=>'selection_configuration_mismatch']);
        $assert($provider->calls === 0, 'Price provider was called before configuration compatibility check');
        $result = $calculator->calculate($equivalentConfigurationA, $selection, 15000000)->toArray();
        $assert($result['configurationDeltaMinor'] === 0 && $result['finalPriceMinor'] === 15000000);
        $assert($provider->calls === 1);
        return;
    }
    throw new RuntimeException('Selection was accepted for a different configuration');
});
$test('configuration calculator returns default upgrade downgrade optional multiple and combined breakdown', static function () use ($assert, $pricingConfiguration): void {
    $prices = ['RAM_32'=>1200000,'RAM_64'=>2000000,'GPU_80'=>8000000,'GPU_65'=>6500000,'HDD_2TB'=>800000,'SOFTWARE_A'=>1000000,'SOFTWARE_B'=>300000];
    $provider = new class($prices) implements OptionPriceProviderInterface {
        public function __construct(private array $prices) {}
        public function getPriceMinor(string $group,string $xmlId):int { return $this->prices[$xmlId]; }
    };
    $configuration = $pricingConfiguration(); $calculator = new ConfigurationPriceCalculator($provider);
    $default = $calculator->calculate($configuration, ConfigurationSelection::fromArray($configuration, []), 15000000)->toArray();
    $assert($default['configurationDeltaMinor'] === 0 && $default['finalPriceMinor'] === 15000000);
    $combined = $calculator->calculate($configuration, ConfigurationSelection::fromArray($configuration, ['RAM'=>'RAM_64','HDD'=>'HDD_2TB','SOFTWARE'=>['SOFTWARE_A']]), 15000000)->toArray();
    $assert($combined['configurationDeltaMinor'] === 2600000 && $combined['finalPriceMinor'] === 17600000);
    $resultObject = $calculator->calculate($configuration, ConfigurationSelection::fromArray($configuration, ['RAM'=>'RAM_64','GPU'=>'GPU_65','HDD'=>'HDD_2TB','SOFTWARE'=>['SOFTWARE_A','SOFTWARE_B']]), 15000000);
    $result = $resultObject->toArray();
    $assert($result['groups']['RAM']['deltaMinor'] === 800000);
    $assert($result['groups']['GPU']['deltaMinor'] === -1500000);
    $assert($result['groups']['HDD']['deltaMinor'] === 800000);
    $assert($result['groups']['SOFTWARE']['deltaMinor'] === 1300000);
    $assert($result['configurationDeltaMinor'] === 1400000 && $result['finalPriceMinor'] === 16400000);
    $assert($resultObject->jsonSerialize() === $result);
    try { $calculator->calculate($configuration, ConfigurationSelection::fromArray($configuration, []), -1); } catch (ConfigurationPricingException $e) { $assert($e->diagnostic()['code']==='invalid_base_price'); return; }
    throw new RuntimeException('Negative base accepted');
});
$test('calculator rejects a negative final price', static function () use ($assert, $pricingConfiguration): void {
    $provider = new class implements OptionPriceProviderInterface { public function getPriceMinor(string $group,string $xmlId):int { return $xmlId === 'GPU_80' ? 8000000 : ($xmlId === 'GPU_65' ? 0 : 0); } };
    $configuration=$pricingConfiguration(); $selection=ConfigurationSelection::fromArray($configuration,['GPU'=>'GPU_65']);
    try { (new ConfigurationPriceCalculator($provider))->calculate($configuration,$selection,100); } catch (ConfigurationPricingException $e) { $assert($e->diagnostic()['code']==='negative_final_price'); return; }
    throw new RuntimeException('Negative final accepted');
});
$test('HL option price provider validates data and caches successful reads', static function () use ($assert): void {
    $repository = new class extends OptionRepository {
        public int $reads=0; public array $rows=['OK'=>['UF_PRICE'=>'1234.56'],'BAD'=>['UF_PRICE'=>'no'],'NEG'=>['UF_PRICE'=>'-1.00'],'MISSING_FIELD'=>[]];
        public function findByTypeAndXmlId(string $type,string $xmlId):?array { ++$this->reads; return $this->rows[$xmlId] ?? null; }
    };
    $provider = new HlOptionPriceProvider($repository);
    $assert($provider->getPriceMinor('RAM','OK') === 123456); $assert($provider->getPriceMinor('RAM','OK') === 123456); $assert($repository->reads === 1);
    foreach ([['NONE','price_option_not_found'],['BAD','invalid_option_price'],['MISSING_FIELD','invalid_option_price'],['NEG','negative_option_price']] as [$id,$code]) {
        try { $provider->getPriceMinor('RAM',$id); } catch (ConfigurationPricingException $e) { $assert($e->diagnostic()['code']===$code); continue; }
        throw new RuntimeException("Provider accepted {$id}");
    }
});

require __DIR__ . '/configurator.php';

$failed = 0;
foreach ($tests as $name => $callback) { try { $callback(); echo "PASS {$name}\n"; } catch (Throwable $e) { ++$failed; fwrite(STDERR, "FAIL {$name}: {$e->getMessage()}\n"); } }
echo count($tests) . " tests, {$failed} failures\n";
exit($failed === 0 ? 0 : 1);
