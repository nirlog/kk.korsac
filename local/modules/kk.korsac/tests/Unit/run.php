<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\Migration\SimplifyHlSchema;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\MigrationRunner;
use KK\Korsac\Install\MigrationStoreInterface;
use KK\Korsac\Install\SchemaComparator;
use KK\Korsac\Install\SchemaDefinition;
use KK\Korsac\Install\SchemaGatewayInterface;
use KK\Korsac\Install\SchemaInstaller;
use KK\Korsac\Install\SqlIndexBuilder;
use KK\Korsac\Repository\OptionTypeRegistry;

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void { $tests[$name] = $callback; };
$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$fakeGateway = static function (): SchemaGatewayInterface {
    return new class implements SchemaGatewayInterface {
        public array $blocks = [], $fields = [], $indexes = [], $rowCounts = [], $deleted = [];
        public int $writes = 0;
        public function getBlock(string $name): ?array { return $this->blocks[$name] ?? null; }
        public function getBlockByTable(string $tableName): ?array { foreach ($this->blocks as $block) { if ($block['TABLE_NAME'] === $tableName) return $block; } return null; }
        public function createBlock(string $name, string $tableName): array { ++$this->writes; return $this->blocks[$name] = ['ID' => count($this->blocks) + 1, 'NAME' => $name, 'TABLE_NAME' => $tableName]; }
        public function getFields(int $blockId): array { return $this->fields[$blockId] ?? []; }
        public function createField(int $blockId, array $field): void { ++$this->writes; $this->fields[$blockId][$field['name']] = ['USER_TYPE_ID' => $field['type'], 'MULTIPLE' => $field['multiple'] ? 'Y' : 'N', 'MANDATORY' => $field['required'] ? 'Y' : 'N', 'SETTINGS' => $field['type'] === 'string' && $field['length'] !== null ? ['MAX_LENGTH' => $field['length']] : []]; }
        public function getIndexes(string $tableName): array { return $this->indexes[$tableName] ?? []; }
        public function findDuplicateRows(string $tableName, array $columns): array { return []; }
        public function createIndex(string $tableName, string $name, array $columns, bool $unique): void { ++$this->writes; $this->indexes[$tableName][$name] = ['columns' => $columns, 'unique' => $unique]; }
        public function rows(string $blockName, array $select = ['*']): array { return []; }
        public function countRows(string $blockName): int { return $this->rowCounts[$blockName] ?? 0; }
        public function deleteBlock(string $blockName): void { $this->deleted[] = $blockName; unset($this->blocks[$blockName]); }
    };
};

$test('module bootstrap and CLI entrypoints are portable', static function () use ($assert): void {
    $namespaces = require __DIR__ . '/fixtures/load_module_include.php';
    $assert(realpath($namespaces['KK\\Korsac'] ?? '') === realpath(dirname(__DIR__, 2) . '/lib'));
    foreach (['tools/schema.php', 'tests/Integration/smoke.php', 'tests/Integration/install_smoke.php', 'tests/Integration/acceptance_smoke.php', 'tests/Integration/schema_v02_migration_smoke.php'] as $file) {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $assert($source !== false && strpos($source, "\$_SERVER['DOCUMENT_ROOT'] = \$documentRoot;") < strpos($source, "require \$documentRoot . '/bitrix/modules/main/include/prolog_before.php';"), $file);
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
$test('migration and installer are idempotent after empty legacy transition', static function () use ($assert, $fakeGateway): void {
    $gateway = $fakeGateway();
    foreach (SimplifyHlSchema::LEGACY_BLOCKS as $name) $gateway->blocks[$name] = ['ID'=>count($gateway->blocks)+1,'NAME'=>$name,'TABLE_NAME'=>'legacy_' . count($gateway->blocks)];
    $installer = new SchemaInstaller($gateway); $migration = new SimplifyHlSchema($gateway, $installer); $migration->up();
    $writes = $gateway->writes; $migration->up();
    $assert($gateway->writes === $writes); $assert(count(SchemaDefinition::entities()) === 12);
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

$failed = 0;
foreach ($tests as $name => $callback) { try { $callback(); echo "PASS {$name}\n"; } catch (Throwable $e) { ++$failed; fwrite(STDERR, "FAIL {$name}: {$e->getMessage()}\n"); } }
echo count($tests) . " tests, {$failed} failures\n";
exit($failed === 0 ? 0 : 1);
