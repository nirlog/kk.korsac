<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

final class SchemaDefinition
{
    public const COMPONENT_TYPES = [
        'CPU' => 'KorsacCpuClass', 'GPU' => 'KorsacGpuClass', 'MB' => 'KorsacMotherboardClass',
        'RAM' => 'KorsacRamClass', 'SSD' => 'KorsacSsdClass', 'PSU' => 'KorsacPsuClass',
        'COOLER' => 'KorsacCoolerClass', 'CASE' => 'KorsacCaseClass', 'SERVICE' => 'KorsacServiceClass',
    ];

    /** @return array<string, array{name:string, table:string, fields:array<string,array>, indexes:array<string,array>}> */
    public static function entities(): array
    {
        $common = self::fields([
            ['UF_XML_ID', 'string', true, null, false, 128], ['UF_NAME', 'string', true, null, false, 255],
            ['UF_PUBLIC_NAME', 'string', true, null, false, 255], ['UF_ACTIVE', 'boolean', true, 1],
            ['UF_SORT', 'integer', true, 500], ['UF_PRICE', 'double', true, 0.0],
            ['UF_PRICE_UPDATED_AT', 'datetime'], ['UF_DESCRIPTION', 'string'],
            ['UF_CREATED_AT', 'datetime', true, 'now'], ['UF_UPDATED_AT', 'datetime', true, 'now'],
        ]);
        $service = self::fields([
            ['UF_XML_ID', 'string', true, null, false, 128], ['UF_NAME', 'string', true, null, false, 255],
            ['UF_PUBLIC_NAME', 'string', true, null, false, 255], ['UF_ACTIVE', 'boolean', true, 1],
            ['UF_SORT', 'integer', true, 500], ['UF_PRICE', 'double', true, 0.0], ['UF_DESCRIPTION', 'string'],
            ['UF_CREATED_AT', 'datetime', true, 'now'], ['UF_UPDATED_AT', 'datetime', true, 'now'],
        ]);
        $definitions = [
            ['KorsacCpuClass','b_hlbd_korsac_cpu_class',$common + self::fields([
                ['UF_VENDOR','string',true,null,false,64],['UF_FAMILY','string',true,null,false,128],['UF_MODEL','string',true,null,false,128],['UF_SOCKET','string',true,null,false,32],['UF_CORE_COUNT','integer',true],['UF_THREAD_COUNT','integer',true],['UF_TDP_W','integer'],['UF_IGPU','boolean',true,0],
            ]), [['ux_korsac_cpu_xml_id',['UF_XML_ID'],true],['ix_korsac_cpu_active_sort',['UF_ACTIVE','UF_SORT']],['ix_korsac_cpu_socket_active',['UF_SOCKET','UF_ACTIVE']]]],
            ['KorsacGpuClass','b_hlbd_korsac_gpu_class',$common + self::fields([
                ['UF_VENDOR_FAMILY','string',true,null,false,64],['UF_GPU_MODEL','string',true,null,false,128],['UF_VRAM_GB','integer',true],['UF_MEMORY_TYPE','string',false,null,false,32],['UF_POWER_CLASS_W','integer'],['UF_MIN_PSU_CLASS','string',false,null,false,128],
            ]), [['ux_korsac_gpu_xml_id',['UF_XML_ID'],true],['ix_korsac_gpu_active_sort',['UF_ACTIVE','UF_SORT']],['ix_korsac_gpu_model_active',['UF_GPU_MODEL','UF_ACTIVE']]]],
            ['KorsacMotherboardClass','b_hlbd_korsac_mb_class',$common + self::fields([
                ['UF_SOCKET','string',true,null,false,32],['UF_CHIPSET','string',true,null,false,32],['UF_FORM_FACTOR','string',true,null,false,32],['UF_RAM_TYPE','string',true,null,false,32],['UF_WIFI','boolean',true,0],['UF_WIFI_STANDARD','string',false,null,false,32],['UF_LAN_SPEED','string',false,null,false,32],['UF_M2_COUNT','integer'],['UF_FRONT_USB_C','boolean',true,0],['UF_PCIE_CLASS','string',false,null,false,32],
            ]), [['ux_korsac_mb_xml_id',['UF_XML_ID'],true],['ix_korsac_mb_socket_chipset_form_active',['UF_SOCKET','UF_CHIPSET','UF_FORM_FACTOR','UF_ACTIVE']],['ix_korsac_mb_wifi_active',['UF_WIFI','UF_ACTIVE']]]],
            ['KorsacRamClass','b_hlbd_korsac_ram_class',$common + self::fields([
                ['UF_CAPACITY_GB','integer',true],['UF_MODULE_COUNT','integer',true],['UF_RAM_TYPE','string',true,null,false,32],['UF_SPEED_MT','integer',true],['UF_CAS_LATENCY','integer'],['UF_PROFILE_TYPE','string',false,null,false,32],['UF_ECC','boolean',true,0],
            ]), [['ux_korsac_ram_xml_id',['UF_XML_ID'],true],['ix_korsac_ram_type_capacity_speed_active',['UF_RAM_TYPE','UF_CAPACITY_GB','UF_SPEED_MT','UF_ACTIVE']]]],
            ['KorsacSsdClass','b_hlbd_korsac_ssd_class',$common + self::fields([
                ['UF_CAPACITY_GB','integer',true],['UF_INTERFACE','string',true,null,false,32],['UF_PCIE_GEN','string',false,null,false,16],['UF_FORM_FACTOR','string',true,null,false,32],['UF_NAND_CLASS','string',false,null,false,32],['UF_DRAM_CLASS','string',false,null,false,32],['UF_ENDURANCE_CLASS','string',false,null,false,64],
            ]), [['ux_korsac_ssd_xml_id',['UF_XML_ID'],true],['ix_korsac_ssd_capacity_interface_active',['UF_CAPACITY_GB','UF_INTERFACE','UF_ACTIVE']]]],
            ['KorsacPsuClass','b_hlbd_korsac_psu_class',$common + self::fields([
                ['UF_POWER_W','integer',true],['UF_EFFICIENCY_CLASS','string',true,null,false,32],['UF_FORM_FACTOR','string',true,null,false,32],['UF_ATX_STANDARD','string',false,null,false,32],['UF_PCIE_POWER_STD','string',false,null,false,32],['UF_MODULAR','boolean',true,0],['UF_NATIVE_GPU_CONN','boolean',true,0],
            ]), [['ux_korsac_psu_xml_id',['UF_XML_ID'],true],['ix_korsac_psu_power_form_active',['UF_POWER_W','UF_FORM_FACTOR','UF_ACTIVE']],['ix_korsac_psu_efficiency_active',['UF_EFFICIENCY_CLASS','UF_ACTIVE']]]],
            ['KorsacCoolerClass','b_hlbd_korsac_cooler_class',$common + self::fields([
                ['UF_COOLER_TYPE','string',true,null,false,32],['UF_COOLING_CLASS','string',true,null,false,64],['UF_MAX_CPU_POWER','integer'],['UF_SOCKET_SUPPORT','string',true,null,true],['UF_SIZE_CLASS','string',false,null,false,64],
            ]), [['ux_korsac_cooler_xml_id',['UF_XML_ID'],true],['ix_korsac_cooler_type_active',['UF_COOLER_TYPE','UF_ACTIVE']]]],
            ['KorsacCaseClass','b_hlbd_korsac_case_class',$common + self::fields([
                ['UF_FORM_FACTOR','string',true,null,false,32],['UF_VOLUME_L','double'],['UF_COLOR','string',true,null,false,32],['UF_PANEL_TYPE','string',false,null,false,32],['UF_MAX_GPU_LEN_MM','integer'],['UF_MAX_GPU_H_MM','integer'],['UF_MAX_GPU_SLOTS','double'],['UF_MAX_COOLER_H_MM','integer'],['UF_PSU_FORMAT','string',true,null,true],['UF_RADIATOR_CLASS','string',false,null,true],['UF_USB_C','boolean',true,0],['UF_IMAGE','file'],['UF_GALLERY','file',false,null,true],
            ]), [['ux_korsac_case_xml_id',['UF_XML_ID'],true],['ix_korsac_case_form_active',['UF_FORM_FACTOR','UF_ACTIVE']],['ix_korsac_case_color_active',['UF_COLOR','UF_ACTIVE']]]],
            ['KorsacServiceClass','b_hlbd_korsac_service_class',$service, [['ux_korsac_service_xml_id',['UF_XML_ID'],true],['ix_korsac_service_active_sort',['UF_ACTIVE','UF_SORT']]]],
            ['KorsacPhysicalSku','b_hlbd_korsac_physical_sku',self::fields([
                ['UF_XML_ID','string',true,null,false,128],['UF_COMPONENT_TYPE','string',true,null,false,32],['UF_CLASS_XML_ID','string',true,null,false,128],['UF_VENDOR','string',true,null,false,128],['UF_MODEL','string',true,null,false,255],['UF_VENDOR_PN','string',false,null,false,128],['UF_APPROVAL_STATUS','string',true,'DRAFT',false,32],['UF_PRIORITY','integer',true,500],['UF_ACTIVE','boolean',true,1],['UF_NOTES','string'],['UF_CREATED_AT','datetime',true,'now'],['UF_UPDATED_AT','datetime',true,'now'],['UF_LENGTH_MM','integer'],['UF_HEIGHT_MM','integer'],['UF_WIDTH_MM','integer'],['UF_SLOT_WIDTH','double'],['UF_POWER_W','integer'],['UF_FORM_FACTOR','string',false,null,false,32],['UF_COOLER_HEIGHT_MM','integer'],['UF_RADIATOR_SIZE_MM','integer'],['UF_RAM_HEIGHT_MM','integer'],['UF_CONNECTOR_SPACE_MM','integer'],
            ]), [['ux_korsac_physical_sku_xml_id',['UF_XML_ID'],true],['ix_korsac_physical_class_status',['UF_COMPONENT_TYPE','UF_CLASS_XML_ID','UF_APPROVAL_STATUS','UF_ACTIVE']],['ix_korsac_physical_vendor_pn',['UF_VENDOR','UF_VENDOR_PN']],['ix_korsac_physical_priority',['UF_PRIORITY']]]],
            ['KorsacSupplierOffer','b_hlbd_korsac_supplier_offer',self::fields([
                ['UF_XML_ID','string',true,null,false,128],['UF_PHYSICAL_SKU','string',true,null,false,128],['UF_SUPPLIER_CODE','string',true,null,false,64],['UF_SUPPLIER_SKU','string',false,null,false,128],['UF_PURCHASE_PRICE','double',true,0.0],['UF_CURRENCY','string',true,'RUB',false,3],['UF_STOCK_QTY','integer'],['UF_AVAILABLE','boolean',true,1],['UF_LEAD_TIME_DAYS','integer'],['UF_UPDATED_AT','datetime',true,'now'],['UF_SOURCE_UPDATED_AT','datetime'],
            ]), [['ux_korsac_supplier_offer_xml_id',['UF_XML_ID'],true],['ix_korsac_supplier_physical_available_updated',['UF_PHYSICAL_SKU','UF_AVAILABLE','UF_UPDATED_AT']],['ix_korsac_supplier_code_sku',['UF_SUPPLIER_CODE','UF_SUPPLIER_SKU']]]],
            ['KorsacValidatedBuild','b_hlbd_korsac_validated_build',self::fields([
                ['UF_XML_ID','string',true,null,false,128],['UF_MODEL_CODE','string',true,null,false,64],['UF_REVISION','string',true,null,false,32],['UF_PROFILE_NAME','string',true,null,false,255],['UF_ACTIVE','boolean',true,1],['UF_CASE_SKU','string',true,null,false,128],['UF_MB_SKU','string',false,null,false,128],['UF_GPU_SKU','string',false,null,false,128],['UF_PSU_SKU','string',false,null,false,128],['UF_COOLER_SKU','string',false,null,false,128],['UF_COMPONENTS_JSON','string'],['UF_THERMAL_STATUS','string',true,'NOT_TESTED',false,32],['UF_NOISE_STATUS','string',true,'NOT_TESTED',false,32],['UF_VALIDATED_AT','datetime'],['UF_NOTES','string'],['UF_UPDATED_AT','datetime',true,'now'],
            ]), [['ux_korsac_validated_build_xml_id',['UF_XML_ID'],true],['ix_korsac_build_model_revision_active',['UF_MODEL_CODE','UF_REVISION','UF_ACTIVE']],['ix_korsac_build_case',['UF_CASE_SKU']],['ix_korsac_build_mb',['UF_MB_SKU']],['ix_korsac_build_gpu',['UF_GPU_SKU']],['ix_korsac_build_psu',['UF_PSU_SKU']],['ix_korsac_build_cooler',['UF_COOLER_SKU']],['ix_korsac_build_thermal',['UF_THERMAL_STATUS']],['ix_korsac_build_noise',['UF_NOISE_STATUS']],['ix_korsac_build_updated',['UF_UPDATED_AT']]]],
        ];
        $result = [];
        foreach ($definitions as [$name, $table, $fields, $indexes]) {
            $indexMap = [];
            foreach ($indexes as $index) {
                [$indexName, $columns, $unique] = array_pad($index, 3, false);
                $indexMap[$indexName] = ['columns' => $columns, 'unique' => $unique ?? false];
            }
            $result[$name] = compact('name', 'table', 'fields') + ['indexes' => $indexMap];
        }
        return $result;
    }

    private static function fields(array $definitions): array
    {
        $fields = [];
        foreach ($definitions as $definition) {
            [$name, $type, $required, $default, $multiple, $length] = array_pad($definition, 6, null);
            $fields[$name] = ['name' => $name, 'type' => $type, 'required' => (bool)$required,
                'default' => $default, 'multiple' => (bool)$multiple, 'length' => $length];
        }
        return $fields;
    }
}
