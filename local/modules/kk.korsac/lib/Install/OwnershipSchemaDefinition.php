<?php

declare(strict_types=1);

namespace KK\Korsac\Install;
final class OwnershipSchemaDefinition
{
    public const HISTORY='b_kk_korsac_system_ownership_history';
    public static function tables(): array
    {
        $id=['type'=>'int','nullable'=>false];
        $optional=['type'=>'int','nullable'=>true];
        $code=['type'=>'varchar(32)','size'=>32,'nullable'=>false];
        $index=static fn(array $columns,bool $unique=false):array=>['columns'=>$columns,'unique'=>$unique];
        return [self::HISTORY=>['columns'=>[
            'ID'=>$id+['auto'=>true],
            'SYSTEM_INTERNAL_ID'=>$id,'REVISION'=>$id,
            'FROM_USER_ID'=>$optional,'TO_USER_ID'=>$optional,
            'EVENT_CODE'=>$code,'SOURCE_CODE'=>$code,'ACTOR_USER_ID'=>$optional,
            'CREATED_AT'=>['type'=>'datetime','nullable'=>false],
        ],'indexes'=>[
            'PRIMARY'=>$index(['ID'],true),
            'ux_kk_korsac_ownership_history_revision'=>$index(['SYSTEM_INTERNAL_ID','REVISION'],true),
            'ix_kk_korsac_ownership_history_system'=>$index(['SYSTEM_INTERNAL_ID']),
            'ix_kk_korsac_ownership_history_to_user'=>$index(['TO_USER_ID']),
            'ix_kk_korsac_ownership_history_actor'=>$index(['ACTOR_USER_ID']),
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
