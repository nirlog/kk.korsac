<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

final class SchemaDefinition
{
    public const OPTION_TYPES = [
        'CPU' => 'KorsacCpu', 'GPU' => 'KorsacGpu', 'MB' => 'KorsacMotherboard',
        'RAM' => 'KorsacRam', 'SSD' => 'KorsacSsd', 'HDD' => 'KorsacHdd',
        'PSU' => 'KorsacPsu', 'COOLER' => 'KorsacCooler', 'CASE' => 'KorsacCase',
        'OS' => 'KorsacOs', 'SOFTWARE' => 'KorsacSoftware', 'SERVICE' => 'KorsacService',
    ];

    private const TABLES = [
        'KorsacCpu' => 'b_hlbd_korsac_cpu', 'KorsacGpu' => 'b_hlbd_korsac_gpu',
        'KorsacMotherboard' => 'b_hlbd_korsac_mb', 'KorsacRam' => 'b_hlbd_korsac_ram',
        'KorsacSsd' => 'b_hlbd_korsac_ssd', 'KorsacHdd' => 'b_hlbd_korsac_hdd',
        'KorsacPsu' => 'b_hlbd_korsac_psu', 'KorsacCooler' => 'b_hlbd_korsac_cooler',
        'KorsacCase' => 'b_hlbd_korsac_case', 'KorsacOs' => 'b_hlbd_korsac_os',
        'KorsacSoftware' => 'b_hlbd_korsac_software', 'KorsacService' => 'b_hlbd_korsac_service',
    ];

    /** @return array<string, array{name:string, table:string, fields:array<string,array>, indexes:array<string,array>}> */
    public static function entities(): array
    {
        $fields = self::fields([
            ['UF_XML_ID', 'string', true, null, false, 128],
            ['UF_NAME', 'string', true, null, false, 255],
            ['UF_PUBLIC_NAME', 'string', true, null, false, 255],
            ['UF_ACTIVE', 'boolean', true, 1],
            ['UF_SORT', 'integer', true, 500],
            ['UF_PRICE', 'double', true, 0.0, false, null, 2],
            ['UF_PRICE_UPDATED_AT', 'datetime'],
            ['UF_DESCRIPTION', 'string'],
            ['UF_IMAGE', 'file'],
            ['UF_CREATED_AT', 'datetime', true, 'now'],
            ['UF_UPDATED_AT', 'datetime', true, 'now'],
        ]);
        $result = [];
        foreach (self::TABLES as $name => $table) {
            $stem = substr($table, strlen('b_hlbd_'));
            $indexes = [
                "ux_{$stem}_xml_id" => ['columns' => [['name' => 'UF_XML_ID', 'length' => 128]], 'unique' => true],
                "ix_{$stem}_active_sort" => ['columns' => [
                    ['name' => 'UF_ACTIVE', 'length' => null], ['name' => 'UF_SORT', 'length' => null],
                ], 'unique' => false],
            ];
            $result[$name] = compact('name', 'table', 'fields', 'indexes');
        }
        return $result;
    }

    private static function fields(array $definitions): array
    {
        $fields = [];
        foreach ($definitions as $definition) {
            [$name, $type, $required, $default, $multiple, $length, $precision] = array_pad($definition, 7, null);
            $fields[$name] = ['name' => $name, 'type' => $type, 'required' => (bool)$required,
                'default' => $default, 'multiple' => (bool)$multiple, 'length' => $length,
                'precision' => $precision];
        }
        return $fields;
    }
}
