<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use KK\Korsac\Install\BitrixSchemaGateway;
use KK\Korsac\Install\OptionMigrationStore;
use KK\Korsac\Install\SchemaMigrationService;
use KK\Korsac\Install\BitrixSnapshotTableGateway;
use KK\Korsac\Install\SnapshotMigrationService;

class kk_korsac extends CModule
{
    public $MODULE_ID = 'kk.korsac';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'KORSAC domain data';
    public $MODULE_DESCRIPTION = 'KORSAC configuration, pricing, immutable snapshots and configured Basket integration.';
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

            $gateway = new BitrixSchemaGateway();
            $store = new OptionMigrationStore();
            (new SchemaMigrationService($store, $gateway))->migrate();
            (new SnapshotMigrationService($store, new BitrixSnapshotTableGateway()))->migrate();
            $this->InstallFiles();
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
        $this->UnInstallFiles();
        UnRegisterModule($this->MODULE_ID);
    }

    public function InstallFiles(): bool
    {
        CopyDirFiles(__DIR__ . '/js', $_SERVER['DOCUMENT_ROOT'] . '/local/js', true, true);
        CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        CopyDirFiles(__DIR__ . '/components', $_SERVER['DOCUMENT_ROOT'] . '/local/components', true, true);
        return true;
    }

    public function UnInstallFiles(): bool
    {
        DeleteDirFilesEx('/local/js/kk/korsac');
        DeleteDirFilesEx('/local/components/kk/korsac.configurator');
        DeleteDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin');
        return true;
    }
}
