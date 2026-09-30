<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use KK\Korsac\Install\BitrixSchemaGateway;
use KK\Korsac\Install\Migration\InitialHlSchema;
use KK\Korsac\Install\Migration\SimplifyHlSchema;
use KK\Korsac\Install\MigrationRunner;
use KK\Korsac\Install\OptionMigrationStore;
use KK\Korsac\Install\SchemaInstaller;

class kk_korsac extends CModule
{
    public $MODULE_ID = 'kk.korsac';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'KORSAC domain data';
    public $MODULE_DESCRIPTION = 'KORSAC Highload-block schema and server-side read infrastructure.';
    public $PARTNER_NAME = 'KORSAC';
    public $PARTNER_URI = '';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall(): void
    {
        global $APPLICATION;
        $registeredHere = false;
        try {
            if (!Loader::includeModule('highloadblock')) {
                throw new RuntimeException('The highloadblock module is required.');
            }

            // Bootstrap from this module directory before registration, so /local and /bitrix holders work alike.
            require_once dirname(__DIR__) . '/include.php';

            if (!ModuleManager::isModuleInstalled($this->MODULE_ID)) {
                RegisterModule($this->MODULE_ID);
                $registeredHere = ModuleManager::isModuleInstalled($this->MODULE_ID);
                if (!$registeredHere) {
                    throw new RuntimeException('Failed to register module kk.korsac.');
                }
            }

            $runner = new MigrationRunner(new OptionMigrationStore());
            $gateway = new BitrixSchemaGateway();
            $installer = new SchemaInstaller($gateway);
            $runner->run([
                new InitialHlSchema($installer),
                new SimplifyHlSchema($gateway, $installer),
            ]);
        } catch (Throwable $exception) {
            if ($registeredHere) {
                UnRegisterModule($this->MODULE_ID);
            }
            $APPLICATION->ThrowException($exception->getMessage());
        }
    }

    public function DoUninstall(): void
    {
        // Deliberately retain HL blocks, rows and migration history.
        UnRegisterModule($this->MODULE_ID);
    }
}
