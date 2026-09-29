<?php

declare(strict_types=1);

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\DateTime;
use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\BitrixSchemaGateway;
use KK\Korsac\Install\SchemaDefinition;
use KK\Korsac\Repository\ComponentClassRepository;

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

$output = [
    'ok' => false,
    'created' => ['componentClasses' => 0, 'physicalSkus' => 0, 'supplierOffers' => 0, 'validatedBuilds' => 0],
    'checks' => [
        'repositoryRead' => false,
        'references' => false,
        'schemaWithFixtures' => false,
        'cleanup' => false,
        'schemaAfterCleanup' => false,
    ],
    'errors' => [],
];
$createdIds = [
    'KorsacCaseClass' => [],
    'KorsacPhysicalSku' => [],
    'KorsacSupplierOffer' => [],
    'KorsacValidatedBuild' => [],
];
$fixtureXmlIds = array_fill_keys(array_keys($createdIds), []);
$dataClasses = [];
$preconditionsPassed = false;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $assert(ModuleManager::isModuleInstalled('kk.korsac'), 'Precondition failed: kk.korsac is not installed.');
    $assert(Loader::includeModule('highloadblock'), 'Precondition failed: highloadblock is not available.');
    $assert(Loader::includeModule('kk.korsac'), 'Precondition failed: kk.korsac cannot be loaded.');

    $gateway = new BitrixSchemaGateway();
    $initialCheck = (new SchemaSelfCheck($gateway))->run();
    $assert($initialCheck['ok'], 'Precondition failed: initial schema self-check is not healthy: ' . json_encode($initialCheck['errors']));
    $preconditionsPassed = true;

    $schema = SchemaDefinition::entities();
    foreach (array_keys($createdIds) as $blockName) {
        $block = HighloadBlockTable::getList(['filter' => ['=NAME' => $blockName], 'limit' => 1])->fetch();
        $assert((bool)$block, "HL block {$blockName} is missing.");
        $dataClasses[$blockName] = HighloadBlockTable::compileEntity($block)->getDataClass();
    }

    $prefix = 'KK_ACCEPT_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(6)) . '_';
    $xmlIds = [
        'atxClass' => $prefix . 'CASE_ATX',
        'miniClass' => $prefix . 'CASE_MINI',
        'skuA' => $prefix . 'CASE_SKU_A',
        'skuB' => $prefix . 'CASE_SKU_B',
        'offerA' => $prefix . 'OFFER_A',
        'offerB' => $prefix . 'OFFER_B',
        'build' => $prefix . 'PLAY_1440_MINI',
    ];
    $now = new DateTime();

    $add = static function (string $blockName, array $fields) use (&$createdIds, &$fixtureXmlIds, &$dataClasses, $schema): int {
        foreach ($schema[$blockName]['fields'] as $fieldName => $definition) {
            if ($definition['required'] && !array_key_exists($fieldName, $fields)) {
                throw new RuntimeException("Required fixture field is missing: {$blockName}.{$fieldName}");
            }
        }
        $dataClass = $dataClasses[$blockName];
        $result = $dataClass::add($fields);
        if (!$result->isSuccess()) {
            throw new RuntimeException("Cannot create {$blockName}: " . implode('; ', $result->getErrorMessages()));
        }
        $id = (int)$result->getId();
        $createdIds[$blockName][] = $id;
        $fixtureXmlIds[$blockName][] = (string)$fields['UF_XML_ID'];
        return $id;
    };

    foreach ([
        [$xmlIds['atxClass'], 'Acceptance ATX case', 'Acceptance ATX case', 'ATX', ['ATX']],
        [$xmlIds['miniClass'], 'Acceptance MINI case', 'Acceptance MINI case', 'MINI_ITX', ['SFX']],
    ] as [$xmlId, $name, $publicName, $formFactor, $psuFormats]) {
        $add('KorsacCaseClass', [
            'UF_XML_ID' => $xmlId,
            'UF_NAME' => $name,
            'UF_PUBLIC_NAME' => $publicName,
            'UF_ACTIVE' => 1,
            'UF_SORT' => 500,
            'UF_PRICE' => 0.0,
            'UF_CREATED_AT' => $now,
            'UF_UPDATED_AT' => $now,
            'UF_FORM_FACTOR' => $formFactor,
            'UF_COLOR' => 'BLACK',
            'UF_PSU_FORMAT' => $psuFormats,
            'UF_USB_C' => 1,
        ]);
        ++$output['created']['componentClasses'];
    }

    foreach ([[$xmlIds['skuA'], 'Acceptance Case SKU A'], [$xmlIds['skuB'], 'Acceptance Case SKU B']] as [$xmlId, $model]) {
        $add('KorsacPhysicalSku', [
            'UF_XML_ID' => $xmlId,
            'UF_COMPONENT_TYPE' => 'CASE',
            'UF_CLASS_XML_ID' => $xmlIds['miniClass'],
            'UF_VENDOR' => 'KORSAC_ACCEPTANCE',
            'UF_MODEL' => $model,
            'UF_APPROVAL_STATUS' => 'APPROVED',
            'UF_PRIORITY' => 500,
            'UF_ACTIVE' => 1,
            'UF_CREATED_AT' => $now,
            'UF_UPDATED_AT' => $now,
            'UF_FORM_FACTOR' => 'MINI_ITX',
        ]);
        ++$output['created']['physicalSkus'];
    }

    foreach ([[$xmlIds['offerA'], 'ACCEPTANCE_A'], [$xmlIds['offerB'], 'ACCEPTANCE_B']] as [$xmlId, $supplierSku]) {
        $add('KorsacSupplierOffer', [
            'UF_XML_ID' => $xmlId,
            'UF_PHYSICAL_SKU' => $xmlIds['skuA'],
            'UF_SUPPLIER_CODE' => 'KK_ACCEPT',
            'UF_SUPPLIER_SKU' => $supplierSku,
            'UF_PURCHASE_PRICE' => 1.0,
            'UF_CURRENCY' => 'RUB',
            'UF_AVAILABLE' => 1,
            'UF_UPDATED_AT' => $now,
        ]);
        ++$output['created']['supplierOffers'];
    }

    $componentsJson = json_encode(['case_sku' => $xmlIds['skuA']], JSON_THROW_ON_ERROR);
    $add('KorsacValidatedBuild', [
        'UF_XML_ID' => $xmlIds['build'],
        'UF_MODEL_CODE' => 'KORSAC_PLAY_1440_MINI',
        'UF_REVISION' => 'ACCEPTANCE',
        'UF_PROFILE_NAME' => 'KORSAC PLAY 1440 MINI acceptance fixture',
        'UF_ACTIVE' => 1,
        'UF_CASE_SKU' => $xmlIds['skuA'],
        'UF_COMPONENTS_JSON' => $componentsJson,
        'UF_THERMAL_STATUS' => 'NOT_TESTED',
        'UF_NOISE_STATUS' => 'NOT_TESTED',
        'UF_UPDATED_AT' => $now,
    ]);
    ++$output['created']['validatedBuilds'];

    $caseClass = $dataClasses['KorsacCaseClass'];
    $classes = $caseClass::getList([
        'filter' => ['@UF_XML_ID' => [$xmlIds['atxClass'], $xmlIds['miniClass']]],
        'select' => ['ID', 'UF_XML_ID', 'UF_FORM_FACTOR'],
    ])->fetchAll();
    $classesByXmlId = array_column($classes, null, 'UF_XML_ID');
    $assert(count($classesByXmlId) === 2, 'Both ATX and MINI component classes must exist.');
    $assert(($classesByXmlId[$xmlIds['atxClass']]['UF_FORM_FACTOR'] ?? null) === 'ATX', 'ATX class form factor is incorrect.');
    $assert(($classesByXmlId[$xmlIds['miniClass']]['UF_FORM_FACTOR'] ?? null) === 'MINI_ITX', 'MINI class form factor is incorrect.');

    $physicalSku = $dataClasses['KorsacPhysicalSku'];
    $skus = $physicalSku::getList([
        'filter' => ['@UF_XML_ID' => [$xmlIds['skuA'], $xmlIds['skuB']]],
        'select' => ['ID', 'UF_XML_ID', 'UF_COMPONENT_TYPE', 'UF_CLASS_XML_ID'],
    ])->fetchAll();
    $assert(count($skus) === 2, 'MINI class must have two Physical SKU rows.');
    foreach ($skus as $sku) {
        $assert($sku['UF_COMPONENT_TYPE'] === 'CASE', 'Physical SKU component type is incorrect.');
        $assert($sku['UF_CLASS_XML_ID'] === $xmlIds['miniClass'], 'Physical SKU class reference is incorrect.');
    }

    $supplierOffer = $dataClasses['KorsacSupplierOffer'];
    $offers = $supplierOffer::getList([
        'filter' => ['@UF_XML_ID' => [$xmlIds['offerA'], $xmlIds['offerB']]],
        'select' => ['ID', 'UF_XML_ID', 'UF_PHYSICAL_SKU'],
    ])->fetchAll();
    $assert(count($offers) === 2, 'Physical SKU A must have two Supplier Offer rows.');
    foreach ($offers as $offer) {
        $assert($offer['UF_PHYSICAL_SKU'] === $xmlIds['skuA'], 'Supplier Offer SKU reference is incorrect.');
    }

    $validatedBuild = $dataClasses['KorsacValidatedBuild'];
    $build = $validatedBuild::getList([
        'filter' => ['=UF_XML_ID' => $xmlIds['build']],
        'select' => ['ID', 'UF_CASE_SKU', 'UF_COMPONENTS_JSON'],
        'limit' => 1,
    ])->fetch();
    $assert((bool)$build, 'Validated Build was not created.');
    $assert($build['UF_CASE_SKU'] === $xmlIds['skuA'], 'Validated Build case reference is incorrect.');
    $decodedComponents = json_decode((string)$build['UF_COMPONENTS_JSON'], true, 512, JSON_THROW_ON_ERROR);
    $assert(($decodedComponents['case_sku'] ?? null) === $xmlIds['skuA'], 'Validated Build JSON reference is incorrect.');

    $repositoryRow = (new ComponentClassRepository())->findByTypeAndXmlId('CASE', $xmlIds['miniClass']);
    $assert($repositoryRow !== null && $repositoryRow['UF_XML_ID'] === $xmlIds['miniClass'], 'ComponentClassRepository cannot read the MINI class.');
    $output['checks']['repositoryRead'] = true;
    $output['checks']['references'] = true;

    $fixtureCheck = (new SchemaSelfCheck($gateway))->run();
    $assert($fixtureCheck['ok'], 'Schema self-check failed with fixtures: ' . json_encode($fixtureCheck['errors']));
    $output['checks']['schemaWithFixtures'] = true;
} catch (Throwable $exception) {
    $output['errors'][] = $exception->getMessage();
} finally {
    if ($preconditionsPassed) {
        $cleanupOk = true;
        foreach (['KorsacValidatedBuild', 'KorsacSupplierOffer', 'KorsacPhysicalSku', 'KorsacCaseClass'] as $blockName) {
            if (!isset($dataClasses[$blockName])) {
                continue;
            }
            $dataClass = $dataClasses[$blockName];
            foreach (array_reverse($createdIds[$blockName]) as $id) {
                try {
                    $deleteResult = $dataClass::delete($id);
                    if (!$deleteResult->isSuccess()) {
                        $cleanupOk = false;
                        $output['errors'][] = "Cleanup failed for {$blockName} ID {$id}: " . implode('; ', $deleteResult->getErrorMessages());
                    }
                } catch (Throwable $exception) {
                    $cleanupOk = false;
                    $output['errors'][] = "Cleanup failed for {$blockName} ID {$id}: {$exception->getMessage()}";
                }
            }
        }

        foreach ($fixtureXmlIds as $blockName => $xmlIdsForBlock) {
            if ($xmlIdsForBlock === [] || !isset($dataClasses[$blockName])) {
                continue;
            }
            $dataClass = $dataClasses[$blockName];
            foreach ($xmlIdsForBlock as $xmlId) {
                try {
                    if ($dataClass::getList(['filter' => ['=UF_XML_ID' => $xmlId], 'select' => ['ID'], 'limit' => 1])->fetch()) {
                        $cleanupOk = false;
                        $output['errors'][] = "Fixture remains after cleanup: {$blockName}.{$xmlId}";
                    }
                } catch (Throwable $exception) {
                    $cleanupOk = false;
                    $output['errors'][] = "Cleanup verification failed for {$blockName}.{$xmlId}: {$exception->getMessage()}";
                }
            }
        }
        $output['checks']['cleanup'] = $cleanupOk;

        try {
            $afterCleanup = (new SchemaSelfCheck(new BitrixSchemaGateway()))->run();
            $output['checks']['schemaAfterCleanup'] = $afterCleanup['ok'];
            if (!$afterCleanup['ok']) {
                $output['errors'][] = 'Schema self-check failed after cleanup: ' . json_encode($afterCleanup['errors']);
            }
        } catch (Throwable $exception) {
            $output['errors'][] = 'Post-cleanup self-check failed: ' . $exception->getMessage();
        }
    }
}

$output['ok'] = $output['errors'] === [] && !in_array(false, $output['checks'], true);
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($output['ok'] ? 0 : 1);
