<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
final class PassportSchemaDefinition
{
    public const TABLE='b_kk_korsac_system_passport';
    public static function tables(): array
    {
        $date=['type'=>'datetime','nullable'=>false];
        $index=static fn(array $columns,bool $unique=false):array=>['columns'=>$columns,'unique'=>$unique];
        return [self::TABLE=>['columns'=>[
            'SYSTEM_INTERNAL_ID'=>['type'=>'int','nullable'=>false],
            'PUBLIC_ID'=>['type'=>'char(32)','size'=>32,'nullable'=>false],
            'SECRET_CODE_HASH'=>['type'=>'char(64)','size'=>64,'nullable'=>false],
            'STATE'=>['type'=>'varchar(16)','size'=>16,'nullable'=>false],
            'TRANSFERRED_AT'=>array_replace($date,['nullable'=>true]),'WARRANTY_STARTED_AT'=>array_replace($date,['nullable'=>true]),
            'PREPARED_AT'=>$date,'UPDATED_AT'=>$date,
        ],'indexes'=>[
            'PRIMARY'=>$index(['SYSTEM_INTERNAL_ID'],true),
            'ux_kk_korsac_passport_public_id'=>$index(['PUBLIC_ID'],true),
            'ux_kk_korsac_passport_secret_hash'=>$index(['SECRET_CODE_HASH'],true),
            'ix_kk_korsac_passport_state'=>$index(['STATE']),
            'ix_kk_korsac_passport_transferred'=>$index(['TRANSFERRED_AT']),
        ]]];
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
