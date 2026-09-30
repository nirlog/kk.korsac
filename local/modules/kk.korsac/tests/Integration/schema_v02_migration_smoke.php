<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Health\SchemaSelfCheck;
use KK\Korsac\Install\BitrixSchemaGateway;
use KK\Korsac\Install\Migration\SimplifyHlSchema;
use KK\Korsac\Install\SchemaDefinition;
use KK\Korsac\Install\SchemaInstaller;

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('highloadblock') || !Loader::includeModule('kk.korsac')) { fwrite(STDERR, "Required modules unavailable.\n"); exit(2); }
$gateway = new BitrixSchemaGateway();
$legacy = [];
foreach (SimplifyHlSchema::LEGACY_BLOCKS as $name) if ($gateway->getBlock($name) !== null) $legacy[$name] = $gateway->countRows($name);
$occupied = array_filter($legacy, static fn(int $count): bool => $count > 0);
if ($occupied !== []) {
    echo json_encode(['ok'=>false, 'safe'=>true, 'action'=>'blocked', 'legacyRows'=>$occupied], JSON_PRETTY_PRINT), PHP_EOL;
    exit(1);
}
$alreadyMigrated = $legacy === [] && array_reduce(array_keys(SchemaDefinition::entities()), static fn(bool $ok,string $name):bool=>$ok && $gateway->getBlock($name)!==null, true);
(new SimplifyHlSchema($gateway, new SchemaInstaller($gateway)))->up();
$result = (new SchemaSelfCheck($gateway))->run();
echo json_encode(['ok'=>$result['ok'], 'safe'=>true, 'action'=>$alreadyMigrated?'already_migrated':'migrated', 'legacyRows'=>$legacy, 'schema'=>$result], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
