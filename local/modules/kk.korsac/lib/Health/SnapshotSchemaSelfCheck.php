<?php

declare(strict_types=1);

namespace KK\Korsac\Health;
use Closure;
use KK\Korsac\Install\Migration\ConfigurationSnapshot;
use Throwable;
/** Existing migration 004 metadata only; no snapshot DDL changes. */
final class SnapshotSchemaSelfCheck
{
    public function __construct(private readonly Closure $describe) {}
    public static function definition(): array
    {
        $columns=[];
        foreach (['ID'=>'int unsigned','SNAPSHOT_KEY'=>'varchar(64)','SNAPSHOT_HASH'=>'char(64)','SCHEMA_VERSION'=>'int','SITE_ID'=>'varchar(10)','IBLOCK_ID'=>'int','PRODUCT_ID'=>'int','PRICE_TYPE_ID'=>'int','CURRENCY'=>'varchar(3)','BASE_PRICE_MINOR'=>'bigint','CONFIGURATION_DELTA_MINOR'=>'bigint','FINAL_PRICE_MINOR'=>'bigint','PAYLOAD'=>'longtext','CREATED_AT'=>'datetime'] as $name=>$type) $columns[$name]=['type'=>$type,'nullable'=>false,'auto'=>$name==='ID'];
        $indexes=['PRIMARY'=>['columns'=>['ID'],'unique'=>true]];
        foreach (ConfigurationSnapshot::INDEXES as $name=>[$fields,$unique]) $indexes[$name]=['columns'=>$fields,'unique'=>$unique];
        return ['columns'=>$columns,'indexes'=>$indexes];
    }
    public function run(): array
    {
        try {
            $errors=ProductionStatusSchemaSelfCheck::compare(ConfigurationSnapshot::TABLE,($this->describe)(ConfigurationSnapshot::TABLE),self::definition());
            foreach ($errors as &$error) $error['code']=str_replace('production_','snapshot_',$error['code']);
            return ['ok'=>$errors===[],'errors'=>$errors];
        } catch (Throwable) { return ['ok'=>false,'errors'=>[['code'=>'snapshot_schema_read_failed']]]; }
    }
}
