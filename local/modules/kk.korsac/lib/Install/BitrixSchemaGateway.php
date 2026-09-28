<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Application;
use Bitrix\Main\SystemException;
use CUserTypeEntity;

final class BitrixSchemaGateway implements SchemaGatewayInterface
{
    public function getBlock(string $name): ?array
    {
        $row = HighloadBlockTable::getList(['filter' => ['=NAME' => $name], 'limit' => 1])->fetch();
        return $row ?: null;
    }

    public function getBlockByTable(string $tableName): ?array
    {
        $row = HighloadBlockTable::getList(['filter' => ['=TABLE_NAME' => $tableName], 'limit' => 1])->fetch();
        return $row ?: null;
    }

    public function createBlock(string $name, string $tableName): array
    {
        $result = HighloadBlockTable::add(['NAME' => $name, 'TABLE_NAME' => $tableName]);
        if (!$result->isSuccess()) {
            throw new SystemException(implode('; ', $result->getErrorMessages()));
        }
        return ['ID' => $result->getId(), 'NAME' => $name, 'TABLE_NAME' => $tableName];
    }

    public function getFields(int $blockId): array
    {
        $result = [];
        $cursor = CUserTypeEntity::GetList([], ['ENTITY_ID' => 'HLBLOCK_' . $blockId]);
        while ($field = $cursor->Fetch()) {
            $result[$field['FIELD_NAME']] = $field;
        }
        return $result;
    }

    public function createField(int $blockId, array $field): void
    {
        $default = $field['default'] === 'now' ? null : $field['default'];
        $settings = [];
        if ($field['type'] === 'string' && $field['length'] !== null) {
            $settings['MAX_LENGTH'] = (int)$field['length'];
            $settings['SIZE'] = min((int)$field['length'], 60);
        }
        if ($default !== null) {
            $settings['DEFAULT_VALUE'] = $default;
        }
        $id = (new CUserTypeEntity())->Add([
            'ENTITY_ID' => 'HLBLOCK_' . $blockId,
            'FIELD_NAME' => $field['name'],
            'USER_TYPE_ID' => $field['type'],
            'XML_ID' => $field['name'],
            'SORT' => 100,
            'MULTIPLE' => $field['multiple'] ? 'Y' : 'N',
            'MANDATORY' => $field['required'] ? 'Y' : 'N',
            'SHOW_FILTER' => 'N', 'SHOW_IN_LIST' => 'Y', 'EDIT_IN_LIST' => 'Y',
            'IS_SEARCHABLE' => 'N', 'SETTINGS' => $settings,
        ]);
        if (!$id) {
            global $APPLICATION;
            $error = $APPLICATION?->GetException()?->GetString() ?? 'unknown error';
            throw new SystemException("Cannot create {$field['name']}: {$error}");
        }
    }

    public function getIndexes(string $tableName): array
    {
        SqlIndexBuilder::assertIdentifier($tableName);
        $rows = Application::getConnection()->query("SHOW INDEX FROM `{$tableName}`");
        $indexes = [];
        while ($row = $rows->fetch()) {
            $name = (string)($row['Key_name'] ?? $row['KEY_NAME']);
            if (strtoupper($name) === 'PRIMARY') {
                continue;
            }
            $indexes[$name] ??= ['unique' => (int)($row['Non_unique'] ?? $row['NON_UNIQUE']) === 0, 'columns' => []];
            $position = (int)($row['Seq_in_index'] ?? $row['SEQ_IN_INDEX']);
            $subPart = $row['Sub_part'] ?? $row['SUB_PART'] ?? null;
            $indexes[$name]['columns'][$position] = [
                'name' => (string)($row['Column_name'] ?? $row['COLUMN_NAME']),
                'length' => $subPart !== null ? (int)$subPart : null,
            ];
        }
        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }
        return $indexes;
    }

    public function findDuplicateRows(string $tableName, array $columns): array
    {
        SqlIndexBuilder::assertIdentifier($tableName);
        $columnNames = [];
        foreach ($columns as $column) {
            $columnName = (string)$column['name'];
            SqlIndexBuilder::assertIdentifier($columnName);
            $columnNames[] = "`{$columnName}`";
        }
        $columnSql = implode(', ', $columnNames);
        $notNullSql = implode(' AND ', array_map(static fn(string $column): string => "{$column} IS NOT NULL", $columnNames));
        $sql = "SELECT {$columnSql}, COUNT(*) AS amount FROM `{$tableName}` WHERE {$notNullSql} "
            . "GROUP BY {$columnSql} HAVING COUNT(*) > 1 LIMIT 20";
        $duplicates = [];
        $rows = Application::getConnection()->query($sql);
        while ($row = $rows->fetch()) {
            $duplicates[] = $row;
        }
        return $duplicates;
    }

    public function createIndex(string $tableName, string $name, array $columns, bool $unique): void
    {
        Application::getConnection()->queryExecute(SqlIndexBuilder::create($tableName, $name, $columns, $unique));
    }

    public function rows(string $blockName, array $select = ['*']): array
    {
        $block = $this->getBlock($blockName);
        if ($block === null) {
            return [];
        }
        $dataClass = HighloadBlockTable::compileEntity($block)->getDataClass();
        return $dataClass::getList(['select' => $select])->fetchAll();
    }
}
