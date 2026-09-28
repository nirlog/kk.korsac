# KORSAC HL — Technical Specification v0.1

Дата: 2026-09-29

Статус: техническая спецификация для реализации HL-структуры KORSAC в 1C-Bitrix.

Основание: архитектура KORSAC Components, подготовленная в `nirlog/kk-template`.

## 1. Цель

Документ переводит архитектуру KORSAC Components в конкретную схему Highload-блоков: технические имена, поля, типы, обязательность, значения по умолчанию, индексы и правила установки/обновления.

Главный принцип:

```text
CATALOG PRODUCT
      ↓
COMPONENT CLASS
      ↓
APPROVED PHYSICAL SKU
      ↓
SUPPLIER OFFER / STOCK
```

Для компактных систем дополнительно используется whitelist проверенных сочетаний:

```text
VALIDATED BUILD PROFILE
```

## 2. Где должна жить реализация

HL-структура живёт в серверном модуле `kk.korsac`, а не в шаблоне сайта.

Module ID:

```text
kk.korsac
```

PHP namespace:

```text
KK\Korsac
```

## 3. Общие соглашения

### 3.1. Имена HL-блоков

| Entity | HL name | DB table |
|---|---|---|
| CPU class | `KorsacCpuClass` | `b_hlbd_korsac_cpu_class` |
| GPU class | `KorsacGpuClass` | `b_hlbd_korsac_gpu_class` |
| Motherboard class | `KorsacMotherboardClass` | `b_hlbd_korsac_mb_class` |
| RAM class | `KorsacRamClass` | `b_hlbd_korsac_ram_class` |
| SSD class | `KorsacSsdClass` | `b_hlbd_korsac_ssd_class` |
| PSU class | `KorsacPsuClass` | `b_hlbd_korsac_psu_class` |
| Cooler class | `KorsacCoolerClass` | `b_hlbd_korsac_cooler_class` |
| Case class | `KorsacCaseClass` | `b_hlbd_korsac_case_class` |
| Service class | `KorsacServiceClass` | `b_hlbd_korsac_service_class` |
| Physical SKU | `KorsacPhysicalSku` | `b_hlbd_korsac_physical_sku` |
| Supplier offer | `KorsacSupplierOffer` | `b_hlbd_korsac_supplier_offer` |
| Validated build | `KorsacValidatedBuild` | `b_hlbd_korsac_validated_build` |

DB table names задаются явно при создании HL-блока и считаются стабильной частью схемы.

### 3.2. Типы Bitrix user fields

Используем стандартные USER_TYPE_ID:

- `string` — коды и короткий текст;
- `integer` — целые числа;
- `double` — цены и числовые значения с дробной частью;
- `boolean` — флаги;
- `datetime` — отметки времени;
- `enumeration` — только для небольших закрытых наборов значений;
- `file` — изображение;
- `string` + `MULTIPLE=Y` — простые списки строк, если отдельная сущность избыточна.

Для frequently-changing справочников предпочтительнее строковый машинный код, а не Bitrix enumeration.

### 3.3. Деньги

В v0.1 сохраняем совместимость с существующим `kk.price-update`:

```text
UF_PRICE : double
```

Правила pricing service:

- валюта по умолчанию — RUB;
- значение нормализуется до 2 знаков после запятой;
- итоговая цена всегда пересчитывается на сервере;
- frontend не является источником истины;
- сравнение float-значений напрямую не используется для бизнес-правил без нормализации.

### 3.4. Stable identifiers

`UF_XML_ID` — неизменяемый машинный идентификатор записи.

После использования записи в заказе или ревизии системы изменение `UF_XML_ID` запрещено.

Все связи между разными HL-блоками в v0.1 строятся через стабильные string codes (`UF_XML_ID`), а не через внутренний numeric `ID`.

## 4. Общие поля всех Component Class HL-блоков

Эти поля должны существовать в CPU/GPU/MB/RAM/SSD/PSU/Cooler/Case class.

| Field | Type | Required | Default | Index | Назначение |
|---|---|---:|---|---|---|
| `UF_XML_ID` | string(128) | Y | — | UNIQUE | стабильный машинный код |
| `UF_NAME` | string(255) | Y | — | — | внутреннее название |
| `UF_PUBLIC_NAME` | string(255) | Y | — | — | название для сайта/заказа |
| `UF_ACTIVE` | boolean | Y | `1` | INDEX | разрешён для новых конфигураций |
| `UF_SORT` | integer | Y | `500` | INDEX | порядок вывода |
| `UF_PRICE` | double | Y | `0.00` | — | текущая цена component class |
| `UF_PRICE_UPDATED_AT` | datetime | N | NULL | — | время последнего обновления цены |
| `UF_DESCRIPTION` | string/text | N | NULL | — | пояснение |
| `UF_CREATED_AT` | datetime | Y | now | — | аудит |
| `UF_UPDATED_AT` | datetime | Y | now | INDEX | аудит |

### 4.1. Ограничения

- `UF_XML_ID` уникален внутри конкретного HL-блока.
- `UF_PRICE >= 0` проверяется application layer.
- inactive class может оставаться в исторических заказах, но не должен предлагаться в новых конфигурациях.
- физическое удаление class, уже использованного в заказах, не допускается; используется `UF_ACTIVE = 0`.

## 5. KorsacCpuClass

Дополнительные поля:

| Field | Type | Req. | Example |
|---|---|---:|---|
| `UF_VENDOR` | string(64) | Y | `AMD` |
| `UF_FAMILY` | string(128) | Y | `Ryzen 7` |
| `UF_MODEL` | string(128) | Y | `9700X` |
| `UF_SOCKET` | string(32) | Y | `AM5` |
| `UF_CORE_COUNT` | integer | Y | `8` |
| `UF_THREAD_COUNT` | integer | Y | `16` |
| `UF_TDP_W` | integer | N | `65` |
| `UF_IGPU` | boolean | Y | `1` |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_ACTIVE, UF_SORT)
INDEX(UF_SOCKET, UF_ACTIVE)
```

## 6. KorsacGpuClass

| Field | Type | Req. | Example |
|---|---|---:|---|
| `UF_VENDOR_FAMILY` | string(64) | Y | `NVIDIA` |
| `UF_GPU_MODEL` | string(128) | Y | `RTX 5070` |
| `UF_VRAM_GB` | integer | Y | `12` |
| `UF_MEMORY_TYPE` | string(32) | N | `GDDR7` |
| `UF_POWER_CLASS_W` | integer | N | `250` |
| `UF_MIN_PSU_CLASS` | string(128) | N | `PSU_650_GOLD_ATX31` |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_ACTIVE, UF_SORT)
INDEX(UF_GPU_MODEL, UF_ACTIVE)
```

Габариты конкретной видеокарты принадлежат Physical SKU.

## 7. KorsacMotherboardClass

| Field | Type | Req. | Example |
|---|---|---:|---|
| `UF_SOCKET` | string(32) | Y | `AM5` |
| `UF_CHIPSET` | string(32) | Y | `B850` |
| `UF_FORM_FACTOR` | string(32) | Y | `ATX`, `MINI_ITX` |
| `UF_RAM_TYPE` | string(32) | Y | `DDR5` |
| `UF_WIFI` | boolean | Y | `1` |
| `UF_WIFI_STANDARD` | string(32) | N | `Wi-Fi 6E` |
| `UF_LAN_SPEED` | string(32) | N | `2.5G` |
| `UF_M2_COUNT` | integer | N | `3` |
| `UF_FRONT_USB_C` | boolean | Y | `0` |
| `UF_PCIE_CLASS` | string(32) | N | `PCIe 5.0` |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_SOCKET, UF_CHIPSET, UF_FORM_FACTOR, UF_ACTIVE)
INDEX(UF_WIFI, UF_ACTIVE)
```

## 8. KorsacRamClass

| Field | Type | Req. | Example |
|---|---|---:|---|
| `UF_CAPACITY_GB` | integer | Y | `32` |
| `UF_MODULE_COUNT` | integer | Y | `2` |
| `UF_RAM_TYPE` | string(32) | Y | `DDR5` |
| `UF_SPEED_MT` | integer | Y | `6000` |
| `UF_CAS_LATENCY` | integer | N | `30` |
| `UF_PROFILE_TYPE` | string(32) | N | `EXPO`, `XMP` |
| `UF_ECC` | boolean | Y | `0` |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_RAM_TYPE, UF_CAPACITY_GB, UF_SPEED_MT, UF_ACTIVE)
```

Высота конкретных модулей RAM хранится в Physical SKU.

## 9. KorsacSsdClass

| Field | Type | Req. | Example |
|---|---|---:|---|
| `UF_CAPACITY_GB` | integer | Y | `1000` |
| `UF_INTERFACE` | string(32) | Y | `NVMe` |
| `UF_PCIE_GEN` | string(16) | N | `4.0` |
| `UF_FORM_FACTOR` | string(32) | Y | `M.2 2280` |
| `UF_NAND_CLASS` | string(32) | N | `TLC` |
| `UF_DRAM_CLASS` | string(32) | N | `DRAM`, `HMB` |
| `UF_ENDURANCE_CLASS` | string(64) | N | internal code |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_CAPACITY_GB, UF_INTERFACE, UF_ACTIVE)
```

Если TLC/DRAM указаны в public name, каждый approved SKU обязан соответствовать заявленному классу.

## 10. KorsacPsuClass

| Field | Type | Req. | Example |
|---|---|---:|---|
| `UF_POWER_W` | integer | Y | `750` |
| `UF_EFFICIENCY_CLASS` | string(32) | Y | `80+ Gold` |
| `UF_FORM_FACTOR` | string(32) | Y | `ATX`, `SFX`, `SFX_L` |
| `UF_ATX_STANDARD` | string(32) | N | `3.1` |
| `UF_PCIE_POWER_STD` | string(32) | N | `PCIe 5.x` |
| `UF_MODULAR` | boolean | Y | `1` |
| `UF_NATIVE_GPU_CONN` | boolean | Y | `1` |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_POWER_W, UF_FORM_FACTOR, UF_ACTIVE)
INDEX(UF_EFFICIENCY_CLASS, UF_ACTIVE)
```

## 11. KorsacCoolerClass

| Field | Type | Req. | Example |
|---|---|---:|---|
| `UF_COOLER_TYPE` | string(32) | Y | `AIR`, `AIO` |
| `UF_COOLING_CLASS` | string(64) | Y | `PREMIUM_AIR` |
| `UF_MAX_CPU_POWER` | integer | N | internal class/watt reference |
| `UF_SOCKET_SUPPORT` | string multiple | Y | `AM5` |
| `UF_SIZE_CLASS` | string(64) | N | internal code |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_COOLER_TYPE, UF_ACTIVE)
```

Фактические габариты cooler/radiator хранятся в Physical SKU.

## 12. KorsacCaseClass

| Field | Type | Req. | Example |
|---|---|---:|---|
| `UF_FORM_FACTOR` | string(32) | Y | `ATX`, `MINI_ITX` |
| `UF_VOLUME_L` | double | N | `18.5` |
| `UF_COLOR` | string(32) | Y | `BLACK` |
| `UF_PANEL_TYPE` | string(32) | N | `GLASS`, `MESH` |
| `UF_MAX_GPU_LEN_MM` | integer | N | `340` |
| `UF_MAX_GPU_H_MM` | integer | N | `150` |
| `UF_MAX_GPU_SLOTS` | double | N | `3.0` |
| `UF_MAX_COOLER_H_MM` | integer | N | `165` |
| `UF_PSU_FORMAT` | string multiple | Y | `ATX`, `SFX` |
| `UF_RADIATOR_CLASS` | string multiple | N | `240`, `280` |
| `UF_USB_C` | boolean | Y | `0` |
| `UF_IMAGE` | file | N | — |
| `UF_GALLERY` | file multiple | N | — |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_FORM_FACTOR, UF_ACTIVE)
INDEX(UF_COLOR, UF_ACTIVE)
```

Case class может быть близок к конкретному физическому SKU, потому что внешний вид корпуса является частью пользовательского выбора.

## 13. KorsacServiceClass

| Field | Type | Req. | Default |
|---|---|---:|---|
| `UF_XML_ID` | string(128) | Y | — |
| `UF_NAME` | string(255) | Y | — |
| `UF_PUBLIC_NAME` | string(255) | Y | — |
| `UF_ACTIVE` | boolean | Y | `1` |
| `UF_SORT` | integer | Y | `500` |
| `UF_PRICE` | double | Y | `0.00` |
| `UF_DESCRIPTION` | text | N | NULL |
| `UF_CREATED_AT` | datetime | Y | now |
| `UF_UPDATED_AT` | datetime | Y | now |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_ACTIVE, UF_SORT)
```

Примеры:

```text
BUILD_STANDARD
BUILD_MINI_PREMIUM
TEST_EXTENDED
```

## 14. KorsacPhysicalSku

Единый внутренний registry конкретных физических моделей.

### 14.1. Общие поля

| Field | Type | Req. | Default | Index |
|---|---|---:|---|---|
| `UF_XML_ID` | string(128) | Y | — | UNIQUE |
| `UF_COMPONENT_TYPE` | string(32) | Y | — | INDEX |
| `UF_CLASS_XML_ID` | string(128) | Y | — | INDEX |
| `UF_VENDOR` | string(128) | Y | — | INDEX |
| `UF_MODEL` | string(255) | Y | — | — |
| `UF_VENDOR_PN` | string(128) | N | NULL | INDEX |
| `UF_APPROVAL_STATUS` | string(32) | Y | `DRAFT` | INDEX |
| `UF_PRIORITY` | integer | Y | `500` | INDEX |
| `UF_ACTIVE` | boolean | Y | `1` | INDEX |
| `UF_NOTES` | text | N | NULL | — |
| `UF_CREATED_AT` | datetime | Y | now | — |
| `UF_UPDATED_AT` | datetime | Y | now | INDEX |

Допустимые `UF_COMPONENT_TYPE`:

```text
CPU
GPU
MB
RAM
SSD
PSU
COOLER
CASE
```

Допустимые `UF_APPROVAL_STATUS`:

```text
DRAFT
APPROVED
SUSPENDED
EOL
```

В v0.1 значения валидируются domain service, а не Bitrix enumeration.

### 14.2. Physical dimensions / compatibility fields

| Field | Type | Назначение |
|---|---|---|
| `UF_LENGTH_MM` | integer | длина компонента/GPU |
| `UF_HEIGHT_MM` | integer | высота |
| `UF_WIDTH_MM` | integer | ширина |
| `UF_SLOT_WIDTH` | double | толщина GPU в слотах |
| `UF_POWER_W` | integer | фактический power class |
| `UF_FORM_FACTOR` | string(32) | ATX/SFX/Mini-ITX/... |
| `UF_COOLER_HEIGHT_MM` | integer | высота air cooler |
| `UF_RADIATOR_SIZE_MM` | integer | 120/240/280/360 |
| `UF_RAM_HEIGHT_MM` | integer | физическая высота RAM |
| `UF_CONNECTOR_SPACE_MM` | integer | резерв под GPU power connector |

### 14.3. Индексы

```text
UNIQUE(UF_XML_ID)
INDEX(UF_COMPONENT_TYPE, UF_CLASS_XML_ID, UF_APPROVAL_STATUS, UF_ACTIVE)
INDEX(UF_VENDOR, UF_VENDOR_PN)
INDEX(UF_PRIORITY)
```

Domain validation обязана проверить, что `UF_CLASS_XML_ID` существует в HL-блоке, соответствующем `UF_COMPONENT_TYPE`.

## 15. KorsacSupplierOffer

Supplier Offer не является публичной сущностью.

| Field | Type | Req. | Default | Index |
|---|---|---:|---|---|
| `UF_XML_ID` | string(128) | Y | — | UNIQUE |
| `UF_PHYSICAL_SKU` | string(128) | Y | — | INDEX |
| `UF_SUPPLIER_CODE` | string(64) | Y | — | INDEX |
| `UF_SUPPLIER_SKU` | string(128) | N | NULL | INDEX |
| `UF_PURCHASE_PRICE` | double | Y | `0.00` | — |
| `UF_CURRENCY` | string(3) | Y | `RUB` | — |
| `UF_STOCK_QTY` | integer | N | NULL | — |
| `UF_AVAILABLE` | boolean | Y | `1` | INDEX |
| `UF_LEAD_TIME_DAYS` | integer | N | NULL | — |
| `UF_UPDATED_AT` | datetime | Y | now | INDEX |
| `UF_SOURCE_UPDATED_AT` | datetime | N | NULL | — |

Индексы:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_PHYSICAL_SKU, UF_AVAILABLE, UF_UPDATED_AT)
INDEX(UF_SUPPLIER_CODE, UF_SUPPLIER_SKU)
```

Изменение supplier offer не должно напрямую менять component-class `UF_PRICE` без запуска утверждённой pricing policy.

## 16. KorsacValidatedBuild

Используется прежде всего для MINI как whitelist реально проверенных сочетаний.

| Field | Type | Req. | Default | Index |
|---|---|---:|---|---|
| `UF_XML_ID` | string(128) | Y | — | UNIQUE |
| `UF_MODEL_CODE` | string(64) | Y | — | INDEX |
| `UF_REVISION` | string(32) | Y | — | INDEX |
| `UF_PROFILE_NAME` | string(255) | Y | — | — |
| `UF_ACTIVE` | boolean | Y | `1` | INDEX |
| `UF_CASE_SKU` | string(128) | Y | — | INDEX |
| `UF_MB_SKU` | string(128) | N | NULL | INDEX |
| `UF_GPU_SKU` | string(128) | N | NULL | INDEX |
| `UF_PSU_SKU` | string(128) | N | NULL | INDEX |
| `UF_COOLER_SKU` | string(128) | N | NULL | INDEX |
| `UF_COMPONENTS_JSON` | text | N | NULL | — |
| `UF_THERMAL_STATUS` | string(32) | Y | `NOT_TESTED` | INDEX |
| `UF_NOISE_STATUS` | string(32) | Y | `NOT_TESTED` | INDEX |
| `UF_VALIDATED_AT` | datetime | N | NULL | — |
| `UF_NOTES` | text | N | NULL | — |
| `UF_UPDATED_AT` | datetime | Y | now | INDEX |

Статусы:

```text
NOT_TESTED
PASSED
FAILED
RETEST_REQUIRED
```

`UF_COMPONENTS_JSON` может хранить полный snapshot whitelist-профиля, но ключевые поля, используемые в запросах совместимости, должны оставаться отдельными колонками.

## 17. Каталожные свойства Bitrix

Свойства каталога ссылаются только на Component Class HL-блоки.

Базовый набор:

```text
KORSAC_MODEL_CODE      string
KORSAC_REVISION        string
KK_FORM_FACTOR         string

KK_CPU                 directory -> KorsacCpuClass, single
KK_GPU                 directory -> KorsacGpuClass, single
KK_MB                  directory -> KorsacMotherboardClass, single
KK_RAM                 directory -> KorsacRamClass, single
KK_RAM_OPTIONS         directory -> KorsacRamClass, multiple
KK_SSD1                directory -> KorsacSsdClass, single
KK_SSD1_OPTIONS        directory -> KorsacSsdClass, multiple
KK_SSD2                directory -> KorsacSsdClass, single/nullable
KK_SSD2_OPTIONS        directory -> KorsacSsdClass, multiple
KK_CASE                directory -> KorsacCaseClass, single
KK_CASE_OPTIONS        directory -> KorsacCaseClass, multiple
KK_PSU                 directory -> KorsacPsuClass, single
KK_COOLER              directory -> KorsacCoolerClass, single
KK_SERVICE             directory -> KorsacServiceClass, single/nullable
```

CPU/GPU/MB/PSU/COOLER `_OPTIONS` не создаются в v0.1 без продуктовой необходимости.

## 18. Default / Options validation

Для каждого `<TYPE>_OPTIONS` применяются правила:

1. default value должен быть active;
2. каждая option должна быть active;
3. options принадлежат тому же component-type HL-блоку;
4. default рекомендуется включать в allowed options для единообразного UI;
5. duplicate XML_ID запрещены;
6. server-side add-to-cart повторно проверяет allowed set независимо от frontend;
7. inactive option может оставаться в старом заказе, но не доступна для нового выбора.

## 19. Индексы и unique constraints

Bitrix UserField metadata само по себе не гарантирует необходимые SQL UNIQUE/index constraints.

Installer/migration обязан после создания HL-таблицы создать необходимые индексы на DB level.

Требования:

- миграции idempotent;
- перед созданием индекс проверяется по имени/составу;
- имена индексов детерминированы;
- existing production data проверяются на duplicates до добавления UNIQUE;
- destructive rebuild таблицы запрещён для обычного upgrade.

Пример naming convention:

```text
ux_korsac_cpu_xml_id
ix_korsac_cpu_active_sort
ux_korsac_physical_sku_xml_id
ix_korsac_physical_class_status
```

## 20. Installer / migration order

Порядок первого создания:

```text
1. Проверить наличие модуля highloadblock.
2. Создать Component Class HL-блоки.
3. Создать common user fields.
4. Создать type-specific user fields.
5. Создать KorsacServiceClass.
6. Создать KorsacPhysicalSku.
7. Создать KorsacSupplierOffer.
8. Создать KorsacValidatedBuild.
9. Создать SQL indexes/unique constraints.
10. Скомпилировать/проверить entities.
11. Создать catalog properties только если catalog/iblock уже сконфигурирован и передан installer configuration.
12. Выполнить schema self-check.
```

Создание HL-блоков не должно зависеть от существования конкретного `IBLOCK_ID`.

Catalog binding выполняется отдельным install/migration step после определения каталога KORSAC.

## 21. Idempotency

Каждый migration step должен поддерживать повторный запуск.

Для каждого HL-блока:

```text
if block missing -> create
if block exists -> verify technical name and table
```

Для каждого UF:

```text
if missing -> create
if exists and compatible -> keep
if exists but incompatible -> fail with explicit diagnostic
```

Нельзя молча менять тип существующего поля с данными.

## 22. Uninstall policy

Обычный uninstall модуля **не должен автоматически удалять KORSAC HL data**.

Рекомендуемая политика:

```text
module uninstall
    -> code removed/disabled
    -> HL data preserved
```

Полное удаление HL-блоков допускается только отдельным explicit action:

```text
REMOVE_DATA = Y
```

с отдельным подтверждением администратора.

Это защищает:

- исторические заказы;
- pricing history;
- approved SKUs;
- system passports;
- supplier mappings.

## 23. Audit / timestamps

`UF_CREATED_AT` и `UF_UPDATED_AT` заполняются server-side.

Правила:

- create -> оба = current server datetime;
- update -> меняется `UF_UPDATED_AT`;
- price update -> дополнительно `UF_PRICE_UPDATED_AT`;
- supplier import -> `UF_UPDATED_AT` + при наличии `UF_SOURCE_UPDATED_AT`.

Frontend не передаёт эти значения как доверенные.

## 24. Domain validation service

Нельзя распределять правила между шаблоном, admin page и AJAX handlers.

Нужен единый серверный validation layer, минимум с операциями:

```text
validateComponentClass(type, xmlId)
validatePhysicalSku(physicalSkuXmlId)
validatePhysicalSkuAgainstClass(physicalSkuXmlId, type, classXmlId)
validateProductConfiguration(productId, selectedOptions)
validateMiniBuild(modelCode, revision, resolvedPhysicalSkus)
```

`kk-template` только отображает результат и не дублирует эти правила.

## 25. Price resolution contract

Pricing engine получает component class, а не supplier offer напрямую.

Минимальный контракт:

```text
ComponentPriceProvider::getPrice(componentType, classXmlId): Money
```

Для product calculation:

```text
ProductPriceCalculator::calculateDefault(productId)
ProductPriceCalculator::calculateConfiguration(productId, selectedOptions)
```

Supplier offers используются отдельным policy/service для пересмотра `UF_PRICE`, но не являются источником цены в пользовательском запросе add-to-cart.

## 26. Snapshot в корзине и заказе

При добавлении товара сохраняется immutable snapshot обещанной конфигурации.

Для каждой строки желательно сохранять:

```text
component_type
class_xml_id
public_name
price_at_order
```

После фактической сборки KORSAC SYSTEM ID дополнительно фиксирует:

```text
physical_sku_xml_id
vendor
model
vendor_pn
```

Исторический заказ не должен зависеть от текущего содержимого HL-блоков.

## 27. Schema self-check

После install/upgrade должна быть доступна диагностическая проверка.

Минимальные проверки:

- все ожидаемые HL-блоки существуют;
- table names совпадают;
- обязательные UF существуют и имеют ожидаемый тип;
- UNIQUE/indexes существуют;
- нет duplicate `UF_XML_ID`;
- Physical SKU ссылаются на существующие classes;
- Supplier Offer ссылаются на существующие Physical SKU;
- Validated Build не содержит неизвестные SKU;
- active product defaults ссылаются на active classes;
- `_OPTIONS` не содержат неизвестные значения.

Проверка должна возвращать structured result:

```text
OK
WARNING
ERROR
```

и понятное административное сообщение.

## 28. Что не реализуем в v0.1

Чтобы не переусложнять первый релиз, пока не нужны:

- универсальный rule engine совместимости;
- graph database;
- автоматический подбор любого SKU по десяткам эвристик;
- dynamic JSON-schema для всех типов компонентов;
- отдельный microservice master-data;
- двусторонняя синхронизация с King-Komp HL;
- автоматическое изменение retail price при каждом изменении supplier offer.

Сначала — строгие component classes, approved physical SKUs и whitelist проверенных MINI builds.

## 29. Acceptance criteria для первой реализации

Реализация считается готовой, если:

1. installer создаёт все HL-блоки и поля на чистой Bitrix-установке;
2. повторный запуск installer/migration ничего не ломает;
3. `UF_XML_ID` защищён UNIQUE constraint;
4. можно создать component class для обычного ATX и MINI;
5. можно привязать несколько approved Physical SKU к одному component class;
6. можно записать несколько supplier offers для одного Physical SKU;
7. можно создать `KORSAC PLAY 1440 MINI` validated build profile;
8. self-check обнаруживает broken references;
9. uninstall без explicit remove-data сохраняет HL данные;
10. pricing layer может получить цену class по `componentType + classXmlId`;
11. template не содержит бизнес-логики HL/price resolution;
12. каталог может использовать default + `_OPTIONS` directory properties без создания торговых предложений.

## 30. Следующий шаг

Первая техническая задача Codex:

> создать каркас серверного KORSAC-модуля и idempotent installer/migrations для HL schema без подключения frontend и без изменения текущего King-Komp.

Первый PR должен быть инфраструктурным: schema + migration + self-check + tests, без каталога и UI.
