# KORSAC product configuration convention

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

Этот документ задаёт соглашение; PR2 не создаёт свойства инфоблока и не реализует configurator или pricing.
