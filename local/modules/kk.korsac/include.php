<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('kk.korsac', [
    'KK\\Korsac\\Install\\MigrationInterface' => 'lib/Install/MigrationInterface.php',
    'KK\\Korsac\\Install\\MigrationStoreInterface' => 'lib/Install/MigrationStoreInterface.php',
    'KK\\Korsac\\Install\\OptionMigrationStore' => 'lib/Install/OptionMigrationStore.php',
    'KK\\Korsac\\Install\\MigrationRunner' => 'lib/Install/MigrationRunner.php',
    'KK\\Korsac\\Install\\SchemaDefinition' => 'lib/Install/SchemaDefinition.php',
    'KK\\Korsac\\Install\\SchemaComparator' => 'lib/Install/SchemaComparator.php',
    'KK\\Korsac\\Install\\SqlIndexBuilder' => 'lib/Install/SqlIndexBuilder.php',
    'KK\\Korsac\\Install\\SchemaGatewayInterface' => 'lib/Install/SchemaGatewayInterface.php',
    'KK\\Korsac\\Install\\BitrixSchemaGateway' => 'lib/Install/BitrixSchemaGateway.php',
    'KK\\Korsac\\Install\\SchemaInstaller' => 'lib/Install/SchemaInstaller.php',
    'KK\\Korsac\\Install\\Migration\\InitialHlSchema' => 'lib/Install/Migration/InitialHlSchema.php',
    'KK\\Korsac\\Health\\SchemaSelfCheck' => 'lib/Health/SchemaSelfCheck.php',
    'KK\\Korsac\\Repository\\ComponentTypeRegistry' => 'lib/Repository/ComponentTypeRegistry.php',
    'KK\\Korsac\\Repository\\ComponentClassRepository' => 'lib/Repository/ComponentClassRepository.php',
    'KK\\Korsac\\Exception\\SchemaMismatchException' => 'lib/Exception/SchemaMismatchException.php',
]);
