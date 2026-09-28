<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\Install\MigrationRunner;
use KK\Korsac\Install\MigrationStoreInterface;
use KK\Korsac\Install\SchemaDefinition;
use KK\Korsac\Repository\ComponentTypeRegistry;

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void { $tests[$name] = $callback; };
$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$test('schema contains all entities with stable mapping', static function () use ($assert): void {
    $entities = SchemaDefinition::entities();
    $assert(count($entities) === 12);
    $assert($entities['KorsacMotherboardClass']['table'] === 'b_hlbd_korsac_mb_class');
    $assert($entities['KorsacValidatedBuild']['table'] === 'b_hlbd_korsac_validated_build');
});
$test('component classes contain common fields', static function () use ($assert): void {
    $entities = SchemaDefinition::entities();
    $common = ['UF_XML_ID','UF_NAME','UF_PUBLIC_NAME','UF_ACTIVE','UF_SORT','UF_PRICE','UF_CREATED_AT','UF_UPDATED_AT'];
    foreach (SchemaDefinition::COMPONENT_TYPES as $block) {
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

$failed = 0;
foreach ($tests as $name => $callback) {
    try { $callback(); echo "PASS {$name}\n"; }
    catch (Throwable $error) { ++$failed; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
}
echo sprintf("%d tests, %d failures\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);
