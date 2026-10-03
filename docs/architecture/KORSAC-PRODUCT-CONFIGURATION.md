# KORSAC product configuration convention

## Архитектура

```text
KORSAC OPTION HL
       ↓
    UF_XML_ID
       ↓
BITRIX DIRECTORY PROPERTY
       ↓
PRODUCT CARD
       ↓
DEFAULT / OPTIONS / MULTI_OPTIONS
       ↓
ProductConfigurationRepository
       ↓
Normalized ProductConfiguration
```

Карточка товара является единственным whitelist допустимых вариантов. Модуль не
вычисляет аппаратную совместимость и не рассчитывает цены. Все свойства имеют
`PROPERTY_TYPE=S`, `USER_TYPE=directory`, `IS_REQUIRED=N` и напрямую ссылаются
на таблицу соответствующего KORSAC HL. Значением ссылки служит `UF_XML_ID`.

## Режимы выбора

`*_DEFAULT` + `*_OPTIONS` означает `SINGLE`: default — базовый вариант, options — разрешённые альтернативы, причём default в options не повторяется. `*_DEFAULT` может быть пустым: это означает отсутствие устройства по умолчанию (например HDD), и искусственная запись `HDD_NONE` не нужна.

`*_MULTI_OPTIONS` означает `MULTIPLE` и разрешает выбрать 0..N значений. Каноническое написание — `_MULTI_OPTIONS`, не `_MULTIOPTIONS`. Тип группы в будущей логике определяется по коду свойства, поэтому отдельный `SELECTION_MODE` не нужен.

## Текущие property codes

```text
KK_CPU_DEFAULT
KK_CPU_OPTIONS
KK_GPU_DEFAULT
KK_GPU_OPTIONS
KK_MB_DEFAULT
KK_MB_OPTIONS
KK_RAM_DEFAULT
KK_RAM_OPTIONS
KK_SSD_DEFAULT
KK_SSD_OPTIONS
KK_HDD_DEFAULT
KK_HDD_OPTIONS
KK_PSU_DEFAULT
KK_PSU_OPTIONS
KK_COOLER_DEFAULT
KK_COOLER_OPTIONS
KK_CASE_DEFAULT
KK_CASE_OPTIONS
KK_OS_DEFAULT
KK_OS_OPTIONS
KK_SOFTWARE_MULTI_OPTIONS
KK_SERVICE_MULTI_OPTIONS
```

CPU, GPU, MB, RAM, SSD, HDD, PSU, COOLER, CASE и OS являются single-choice. Software и Service являются независимыми multiple-choice группами. Значения свойств ссылаются на стабильные `UF_XML_ID` соответствующего option HL.

Пример optional single-choice:

```text
KK_HDD_DEFAULT = empty
KK_HDD_OPTIONS = HDD_2TB, HDD_4TB, HDD_8TB
```

## Явная установка и проверка

Установка модуля намеренно не пытается определить каталог и не создаёт свойства.
Администратор должен передать существующий инфоблок явно:

```bash
php local/modules/kk.korsac/tools/catalog.php install-properties --iblock=<ID>
php local/modules/kk.korsac/tools/catalog.php check-properties --iblock=<ID>
```

Установка идемпотентна: корректные свойства остаются неизменными, а несовместимое
существующее свойство приводит к диагностике вместо автоматического исправления.
`DEFAULT` имеет `MULTIPLE=N`; `OPTIONS` и `MULTI_OPTIONS` — `MULTIPLE=Y`.

Нормализованная модель содержит для single-группы `mode`, nullable `default` и
упорядоченный список `options`; multiple-группа содержит только `mode` и
`options`. Repository проверяет существование и `UF_ACTIVE` всех ссылок,
дубликаты, а также отсутствие default в alternatives. Пустой default допустим.

Интеграционная проверка выполняется только с явно указанными идентификаторами:

```bash
php local/modules/kk.korsac/tests/Integration/catalog_properties_smoke.php --iblock=<ID>
php local/modules/kk.korsac/tests/Integration/product_configuration_smoke.php --iblock=<ID> --product=<ID>
```

Скрипты без обязательных ID завершаются с кодом 2 до загрузки Bitrix и ничего не
изменяют. Product configuration smoke выполняет только чтение.

## Presentation is separate

The `KK_<GROUP>_VIEW` list properties are not configuration references and are deliberately excluded from `CatalogPropertySchema::properties()` and `PropertyCodeParser`. They do not affect DEFAULT/OPTIONS/MULTI_OPTIONS semantics. See [KORSAC-PRESENTATION.md](KORSAC-PRESENTATION.md).
