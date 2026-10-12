<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
/** Single SQL/ORM/health metadata source. No Bitrix cross-module foreign keys. */
final class SystemSchemaDefinition
{
    public const SYSTEM = 'b_kk_korsac_system';
    public const SEQUENCE = 'b_kk_korsac_system_sequence';
    public const COMPONENT = 'b_kk_korsac_system_component';
    public static function tables(): array
    {
        $id = ['type'=>'int unsigned', 'nullable'=>false, 'auto'=>true];
        $integer = ['type'=>'int unsigned', 'nullable'=>false];
        $date = ['type'=>'datetime', 'nullable'=>false];
        $string = static fn(int $size, bool $nullable = false): array => ['type'=>"varchar({$size})", 'size'=>$size, 'nullable'=>$nullable];
        $index = static fn(array $columns, bool $unique = false): array => ['columns'=>$columns, 'unique'=>$unique];
        return [
            self::SYSTEM => [
                'columns'=>[
                    'ID'=>$id, 'SYSTEM_ID'=>$string(12), 'SITE_ID'=>$string(10), 'SYSTEM_TYPE'=>$string(10), 'MODEL_NAME'=>$string(255),
                    'IBLOCK_ID'=>array_replace($integer,['nullable'=>true]), 'PRODUCT_ID'=>array_replace($integer,['nullable'=>true]),
                    'ORDER_ID'=>$integer, 'ORDER_BASKET_ID'=>$integer, 'ORDER_UNIT_INDEX'=>$integer,
                    'CONFIG_SNAPSHOT_KEY'=>$string(64,true), 'USER_ID'=>array_replace($integer,['nullable'=>true]),
                    'LIFECYCLE_STATE'=>$string(16), 'CREATED_AT'=>$date, 'UPDATED_AT'=>$date,
                ],
                'indexes'=>[
                    'PRIMARY'=>$index(['ID'],true), 'ux_kk_korsac_system_id'=>$index(['SYSTEM_ID'],true),
                    'ux_kk_korsac_system_order_unit'=>$index(['ORDER_ID','ORDER_BASKET_ID','ORDER_UNIT_INDEX'],true),
                    'ix_kk_korsac_system_user'=>$index(['USER_ID']), 'ix_kk_korsac_system_order'=>$index(['ORDER_ID']),
                    'ix_kk_korsac_system_product'=>$index(['PRODUCT_ID']), 'ix_kk_korsac_system_snapshot'=>$index(['CONFIG_SNAPSHOT_KEY']),
                    'ix_kk_korsac_system_lifecycle'=>$index(['LIFECYCLE_STATE']),
                ],
            ],
            self::SEQUENCE => [
                'columns'=>['PERIOD'=>['type'=>'char(4)','size'=>4,'nullable'=>false], 'LAST_VALUE'=>$integer, 'UPDATED_AT'=>$date],
                'indexes'=>['PRIMARY'=>$index(['PERIOD'],true)],
            ],
            self::COMPONENT => [
                'columns'=>[
                    'ID'=>$id, 'SYSTEM_ID'=>$integer, 'TYPE_CODE'=>$string(32), 'SLOT_KEY'=>$string(64), 'SLOT_LABEL'=>$string(255),
                    'NAME'=>$string(255), 'SERIAL_NUMBER'=>$string(255,true), 'INSTALLED_AT'=>$date,
                    'REMOVED_AT'=>array_replace($date,['nullable'=>true]), 'CREATED_AT'=>$date, 'UPDATED_AT'=>$date,
                ],
                'indexes'=>[
                    'PRIMARY'=>$index(['ID'],true), 'ix_kk_korsac_component_system'=>$index(['SYSTEM_ID']),
                    'ix_kk_korsac_component_slot'=>$index(['SYSTEM_ID','SLOT_KEY']),
                    'ix_kk_korsac_component_active'=>$index(['SYSTEM_ID','REMOVED_AT']),
                ],
            ],
        ];
    }
    public static function createSql(string $table): string
    {
        $definition = self::tables()[$table];
        $parts = [];
        foreach ($definition['columns'] as $name=>$column) {
            $parts[] = "`{$name}` " . strtoupper($column['type']) . ($column['nullable'] ? ' NULL' : ' NOT NULL') . (!empty($column['auto']) ? ' AUTO_INCREMENT' : '');
        }
        $primary = implode(',', array_map(static fn(string $name): string => "`{$name}`", $definition['indexes']['PRIMARY']['columns']));
        $parts[] = "PRIMARY KEY ({$primary})";
        return "CREATE TABLE `{$table}` (" . implode(',', $parts) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
    public static function ormMap(string $table): array
    {
        $map = [];
        foreach (self::tables()[$table]['columns'] as $name=>$column) {
            $class = str_starts_with($column['type'], 'int') ? \Bitrix\Main\Entity\IntegerField::class : ($column['type'] === 'datetime' ? \Bitrix\Main\Entity\DatetimeField::class : \Bitrix\Main\Entity\StringField::class);
            $field = new $class($name);
            if (isset($column['size'])) $field->configureSize($column['size']);
            if (in_array($name, self::tables()[$table]['indexes']['PRIMARY']['columns'], true)) $field->configurePrimary();
            if (!empty($column['auto'])) $field->configureAutocomplete();
            elseif (!$column['nullable']) $field->configureRequired();
            if ($column['nullable']) $field->configureNullable();
            $map[] = $field;
        }
        return $map;
    }
}
