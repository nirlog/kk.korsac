<?php
declare(strict_types=1);
namespace KK\Korsac\Install;
use Bitrix\Main\Application;
final class BitrixSnapshotTableGateway implements SnapshotTableGatewayInterface
{
    private object $connection;
    public function __construct() { $this->connection=Application::getConnection(); }
    public function tableExists(string $table): bool { return $this->connection->isTableExists($table); }
    public function createTable(string $table): void
    {
        $this->connection->queryExecute("CREATE TABLE `{$table}` (`ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,`SNAPSHOT_KEY` VARCHAR(64) NOT NULL,`SNAPSHOT_HASH` CHAR(64) NOT NULL,`SCHEMA_VERSION` INT NOT NULL,`SITE_ID` VARCHAR(10) NOT NULL,`IBLOCK_ID` INT NOT NULL,`PRODUCT_ID` INT NOT NULL,`PRICE_TYPE_ID` INT NOT NULL,`CURRENCY` VARCHAR(3) NOT NULL,`BASE_PRICE_MINOR` BIGINT NOT NULL,`CONFIGURATION_DELTA_MINOR` BIGINT NOT NULL,`FINAL_PRICE_MINOR` BIGINT NOT NULL,`PAYLOAD` LONGTEXT NOT NULL,`CREATED_AT` DATETIME NOT NULL,PRIMARY KEY (`ID`)) ENGINE=InnoDB");
    }
    public function indexExists(string $table,string $index): bool
    {
        $rows=$this->connection->query("SHOW INDEX FROM `{$table}` WHERE Key_name='".$this->connection->getSqlHelper()->forSql($index)."'");
        return (bool)$rows->fetch();
    }
    public function createIndex(string $table,string $index,array $columns,bool $unique): void
    {
        $kind=$unique?'UNIQUE INDEX':'INDEX'; $quoted=implode(',',array_map(static fn(string $c):string=>"`{$c}`",$columns));
        $this->connection->queryExecute("CREATE {$kind} `{$index}` ON `{$table}` ({$quoted})");
    }
}
