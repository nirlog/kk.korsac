<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\MigrationRunner;
use KK\Korsac\Install\MigrationStoreInterface;
use KK\Korsac\Install\SchemaComparator;
use KK\Korsac\Install\SchemaDefinition;
use KK\Korsac\Install\SchemaGatewayInterface;
use KK\Korsac\Install\SchemaInstaller;
use KK\Korsac\Install\SqlIndexBuilder;
use KK\Korsac\Repository\ComponentTypeRegistry;

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void { $tests[$name] = $callback; };
$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$test('module bootstrap registers namespace from its own directory', static function () use ($assert): void {
    $namespaces = require __DIR__ . '/fixtures/load_module_include.php';
    $expected = realpath(dirname(__DIR__, 2) . '/lib');
    $actual = realpath($namespaces['KK\\Korsac'] ?? '');
    $assert($expected !== false && $actual === $expected, 'Namespace root is not the module lib directory');
    $includeSource = file_get_contents(dirname(__DIR__, 2) . '/include.php');
    $assert($includeSource !== false && !str_contains($includeSource, '/bitrix/modules/kk.korsac'), 'Bootstrap contains a hard-coded Bitrix module holder');
});

$test('CLI entrypoints synchronize DOCUMENT_ROOT before Bitrix bootstrap', static function () use ($assert): void {
    $moduleRoot = dirname(__DIR__, 2);
    foreach (['tools/schema.php', 'tests/Integration/smoke.php', 'tests/Integration/install_smoke.php'] as $relativePath) {
        $source = file_get_contents($moduleRoot . '/' . $relativePath);
        $assert($source !== false, "Cannot read {$relativePath}");
        $syncPosition = strpos($source, '$_SERVER[\'DOCUMENT_ROOT\'] = $documentRoot;');
        $prologPosition = strpos($source, <<<'PHP'
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
PHP
        );
        $assert($syncPosition !== false, "{$relativePath} does not synchronize DOCUMENT_ROOT");
        $assert($prologPosition !== false && $syncPosition < $prologPosition, "{$relativePath} synchronizes DOCUMENT_ROOT too late");
    }
});

$test('schema contains all entities with stable mapping', static function () use ($assert): void {
    $entities = SchemaDefinition::entities();
    $assert(count($entities) === 12);
    $assert($entities['KorsacMotherboardClass']['table'] === 'b_hlbd_korsac_mb_class');
    $assert($entities['KorsacValidatedBuild']['table'] === 'b_hlbd_korsac_validated_build');
});
$test('component classes contain common fields', static function () use ($assert): void {
    $entities = SchemaDefinition::entities();
    $common = ['UF_XML_ID','UF_NAME','UF_PUBLIC_NAME','UF_ACTIVE','UF_SORT','UF_PRICE','UF_CREATED_AT','UF_UPDATED_AT'];
    foreach (SchemaDefinition::COMPONENT_CLASS_TYPES as $block) {
        foreach ($common as $field) { $assert(isset($entities[$block]['fields'][$field]), "{$block}.{$field} missing"); }
        $assert($entities[$block]['fields']['UF_XML_ID']['length'] === 128);
    }
});
$test('migration runner applies migration once', static function () use ($assert): void {
    $store = new class implements MigrationStoreInterface {
        public array $ids = [];
        public function has(string $migrationId): bool { return isset($this->ids[$migrationId]); }
        public function markApplied(string $migrationId): void { $this->ids[$migrationId] = true; }
    };
    $migration = new class implements MigrationInterface {
        public int $runs = 0;
        public function id(): string { return 'test'; }
        public function up(): void { ++$this->runs; }
    };
    $runner = new MigrationRunner($store);
    $assert($runner->run([$migration]) === ['test']);
    $assert($runner->run([$migration]) === []);
    $assert($migration->runs === 1);
});
$test('self-check detects broken references duplicates and negative prices', static function () use ($assert): void {
    $errors = SchemaSelfCheck::analyzeData([
        'KorsacCpuClass' => [
            ['UF_XML_ID' => 'CPU_A', 'UF_PRICE' => -1], ['UF_XML_ID' => 'CPU_A', 'UF_PRICE' => 10],
        ],
        'KorsacPhysicalSku' => [['UF_XML_ID' => 'SKU_A', 'UF_COMPONENT_TYPE' => 'CPU', 'UF_CLASS_XML_ID' => 'MISSING']],
        'KorsacSupplierOffer' => [['UF_XML_ID' => 'OFFER_A', 'UF_PHYSICAL_SKU' => 'MISSING']],
        'KorsacValidatedBuild' => [['UF_XML_ID' => 'BUILD_A', 'UF_CASE_SKU' => 'MISSING']],
    ]);
    $codes = array_column($errors, 'code');
    foreach (['negative_price','duplicate_xml_id','broken_class_reference','broken_sku_reference','broken_build_reference'] as $code) {
        $assert(in_array($code, $codes, true), "{$code} was not detected");
    }
});
$test('self-check accepts valid synthetic references', static function () use ($assert): void {
    $errors = SchemaSelfCheck::analyzeData([
        'KorsacCpuClass' => [['UF_XML_ID' => 'CPU_A', 'UF_PRICE' => 1]],
        'KorsacCaseClass' => [['UF_XML_ID' => 'CASE_A', 'UF_PRICE' => 1]],
        'KorsacPhysicalSku' => [
            ['UF_XML_ID' => 'CPU_SKU', 'UF_COMPONENT_TYPE' => 'CPU', 'UF_CLASS_XML_ID' => 'CPU_A'],
            ['UF_XML_ID' => 'CASE_SKU', 'UF_COMPONENT_TYPE' => 'CASE', 'UF_CLASS_XML_ID' => 'CASE_A'],
        ],
        'KorsacSupplierOffer' => [['UF_XML_ID' => 'OFFER', 'UF_PHYSICAL_SKU' => 'CPU_SKU']],
        'KorsacValidatedBuild' => [['UF_XML_ID' => 'BUILD', 'UF_CASE_SKU' => 'CASE_SKU', 'UF_COMPONENTS_JSON' => '{"cpu_sku":"CPU_SKU"}']],
    ]);
    $assert($errors === [], json_encode($errors));
});
$test('component type registry rejects unknown type', static function () use ($assert): void {
    $assert(ComponentTypeRegistry::blockName('cpu') === 'KorsacCpuClass');
    try { ComponentTypeRegistry::blockName('unknown'); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Unknown component type was accepted');
});

$test('schema installer remains idempotent with prefixed indexes', static function () use ($assert): void {
    $gateway = new class implements SchemaGatewayInterface {
        public array $blocks = [];
        public array $fields = [];
        public array $indexes = [];
        public int $writes = 0;
        public function getBlock(string $name): ?array { return $this->blocks[$name] ?? null; }
        public function getBlockByTable(string $tableName): ?array {
            foreach ($this->blocks as $block) { if ($block['TABLE_NAME'] === $tableName) { return $block; } }
            return null;
        }
        public function createBlock(string $name, string $tableName): array {
            ++$this->writes;
            return $this->blocks[$name] = ['ID' => count($this->blocks) + 1, 'NAME' => $name, 'TABLE_NAME' => $tableName];
        }
        public function getFields(int $blockId): array { return $this->fields[$blockId] ?? []; }
        public function createField(int $blockId, array $field): void {
            ++$this->writes;
            $this->fields[$blockId][$field['name']] = [
                'USER_TYPE_ID' => $field['type'], 'MULTIPLE' => $field['multiple'] ? 'Y' : 'N',
                'MANDATORY' => $field['required'] ? 'Y' : 'N',
                'SETTINGS' => $field['type'] === 'string' && $field['length'] !== null ? ['MAX_LENGTH' => $field['length']] : [],
            ];
        }
        public function getIndexes(string $tableName): array { return $this->indexes[$tableName] ?? []; }
        public function findDuplicateRows(string $tableName, array $columns): array { return []; }
        public function createIndex(string $tableName, string $name, array $columns, bool $unique): void {
            ++$this->writes;
            $this->indexes[$tableName][$name] = ['columns' => $columns, 'unique' => $unique];
        }
        public function rows(string $blockName, array $select = ['*']): array { return []; }
    };
    $installer = new SchemaInstaller($gateway);
    $installer->install();
    $writesAfterFirstInstall = $gateway->writes;
    $installer->install();
    $assert($writesAfterFirstInstall > 0);
    $assert($gateway->writes === $writesAfterFirstInstall, 'Second install performed schema writes');
});

$test('indexed string fields use bounded SQL prefixes', static function () use ($assert): void {
    foreach (SchemaDefinition::entities() as $entity) {
        foreach ($entity['indexes'] as $index) {
            foreach ($index['columns'] as $column) {
                $field = $entity['fields'][$column['name']];
                if ($field['type'] === 'string') {
                    $assert($field['length'] !== null, "{$entity['name']}.{$column['name']} is unbounded");
                    $assert($column['length'] === $field['length'], "{$entity['name']}.{$column['name']} prefix mismatch");
                } else {
                    $assert($column['length'] === null, "Non-string index column has a prefix");
                }
            }
        }
    }
    $xmlIndex = SchemaDefinition::entities()['KorsacCpuClass']['indexes']['ux_korsac_cpu_xml_id'];
    $sql = SqlIndexBuilder::create('b_hlbd_korsac_cpu_class', 'ux_korsac_cpu_xml_id', $xmlIndex['columns'], true);
    $assert($sql === 'CREATE UNIQUE INDEX `ux_korsac_cpu_xml_id` ON `b_hlbd_korsac_cpu_class` (`UF_XML_ID`(128))');
});
$test('index comparison includes order uniqueness and prefix lengths', static function () use ($assert): void {
    $expected = ['unique' => true, 'columns' => [
        ['name' => 'UF_CODE', 'length' => 128], ['name' => 'UF_ACTIVE', 'length' => null],
    ]];
    $fromShowIndex = ['unique' => 1, 'columns' => [
        ['name' => 'uf_code', 'length' => '128'], ['name' => 'UF_ACTIVE', 'length' => null],
    ]];
    $assert(SchemaComparator::indexIsCompatible($fromShowIndex, $expected));
    $wrongPrefix = $fromShowIndex;
    $wrongPrefix['columns'][0]['length'] = 64;
    $assert(!SchemaComparator::indexIsCompatible($wrongPrefix, $expected));
    $wrongOrder = $fromShowIndex;
    $wrongOrder['columns'] = array_reverse($wrongOrder['columns']);
    $assert(!SchemaComparator::indexIsCompatible($wrongOrder, $expected));
    $notUnique = $fromShowIndex;
    $notUnique['unique'] = false;
    $assert(!SchemaComparator::indexIsCompatible($notUnique, $expected));
});
$test('bounded string fields require matching MAX_LENGTH metadata', static function () use ($assert): void {
    $expected = SchemaDefinition::entities()['KorsacCpuClass']['fields']['UF_XML_ID'];
    $actual = ['USER_TYPE_ID' => 'string', 'MULTIPLE' => 'N', 'MANDATORY' => 'Y', 'SETTINGS' => ['MAX_LENGTH' => 128]];
    $assert(SchemaComparator::fieldIsCompatible($actual, $expected));
    $actual['SETTINGS']['MAX_LENGTH'] = 0;
    $assert(!SchemaComparator::fieldIsCompatible($actual, $expected));
});
$test('component class and physical SKU type sets are distinct', static function () use ($assert): void {
    $assert(isset(SchemaDefinition::COMPONENT_CLASS_TYPES['SERVICE']));
    $assert(!isset(SchemaDefinition::PHYSICAL_SKU_TYPES['SERVICE']));
    $errors = SchemaSelfCheck::analyzeData([
        'KorsacServiceClass' => [['UF_XML_ID' => 'SERVICE_A', 'UF_PRICE' => 0]],
        'KorsacPhysicalSku' => [['UF_XML_ID' => 'SERVICE_SKU', 'UF_COMPONENT_TYPE' => 'SERVICE', 'UF_CLASS_XML_ID' => 'SERVICE_A']],
    ]);
    $assert(in_array('broken_class_reference', array_column($errors, 'code'), true));
});

$failed = 0;
foreach ($tests as $name => $callback) {
    try { $callback(); echo "PASS {$name}\n"; }
    catch (Throwable $error) { ++$failed; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
}
echo sprintf("%d tests, %d failures\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);
