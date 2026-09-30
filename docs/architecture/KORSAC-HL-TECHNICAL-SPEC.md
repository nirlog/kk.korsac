# KORSAC HL technical specification v0.2

Дата: 2026-09-30

## 1. Модель

```text
CATALOG PRODUCT
      ↓
DEFAULT / OPTIONS / MULTI_OPTIONS
      ↓
KORSAC OPTION HL
```

Одна строка HL — один доступный вариант комплектующего, ОС, программы или услуги. Совместимость и разрешённый ассортимент задаются администратором в свойствах конкретного товара; модуль не рассчитывает совместимость. `UF_PRICE` — абсолютная текущая цена варианта, не доплата. Расчёт разницы цен не входит в v0.2.

## 2. Справочники

| Type | HL block | DB table |
|---|---|---|
| CPU | `KorsacCpu` | `b_hlbd_korsac_cpu` |
| GPU | `KorsacGpu` | `b_hlbd_korsac_gpu` |
| MB | `KorsacMotherboard` | `b_hlbd_korsac_mb` |
| RAM | `KorsacRam` | `b_hlbd_korsac_ram` |
| SSD | `KorsacSsd` | `b_hlbd_korsac_ssd` |
| HDD | `KorsacHdd` | `b_hlbd_korsac_hdd` |
| PSU | `KorsacPsu` | `b_hlbd_korsac_psu` |
| COOLER | `KorsacCooler` | `b_hlbd_korsac_cooler` |
| CASE | `KorsacCase` | `b_hlbd_korsac_case` |
| OS | `KorsacOs` | `b_hlbd_korsac_os` |
| SOFTWARE | `KorsacSoftware` | `b_hlbd_korsac_software` |
| SERVICE | `KorsacService` | `b_hlbd_korsac_service` |

Все справочники независимы и имеют одинаковую структуру. Старые уровни ComponentClass, PhysicalSku, SupplierOffer и ValidatedBuild не входят в target schema v0.2.

## 3. Общие поля

| Field | Type | Required | Default | Meaning |
|---|---|---:|---|---|
| `UF_XML_ID` | string(128) | Y | — | стабильный машинный код |
| `UF_NAME` | string(255) | Y | — | внутреннее название |
| `UF_PUBLIC_NAME` | string(255) | Y | — | название для покупателя |
| `UF_ACTIVE` | boolean | Y | `1` | доступность для новых конфигураций |
| `UF_SORT` | integer | Y | `500` | порядок вывода |
| `UF_PRICE` | double | Y | `0.00` | абсолютная цена |
| `UF_PRICE_UPDATED_AT` | datetime | N | NULL | время обновления цены |
| `UF_DESCRIPTION` | string/text | N | NULL | описание |
| `UF_CREATED_AT` | datetime | Y | now | создание |
| `UF_UPDATED_AT` | datetime | Y | now | изменение |

Технические характеристики заранее не моделируются. `UF_XML_ID` является стабильной ссылкой из каталожных свойств; внутренний `ID` для такой ссылки не используется.

## 4. Индексы

Каждый справочник имеет только:

```text
UNIQUE(UF_XML_ID)
INDEX(UF_ACTIVE, UF_SORT)
```

Поскольку Bitrix может хранить `string` UF как SQL `TEXT`, `MAX_LENGTH` сохраняется в metadata, а индекс строкового поля создаётся с prefix length и сравнивается с учётом `SHOW INDEX.Sub_part`.

## 5. Product properties

Single-choice определяется парой `<PREFIX>_DEFAULT` и `<PREFIX>_OPTIONS`; default не дублируется в options. Пустой default означает отсутствие варианта по умолчанию. Multiple-choice определяется единственным каноническим суффиксом `<PREFIX>_MULTI_OPTIONS` и допускает 0..N значений. Отдельное поле `SELECTION_MODE` не создаётся. Полная конвенция приведена в [KORSAC-PRODUCT-CONFIGURATION.md](KORSAC-PRODUCT-CONFIGURATION.md). Модуль v0.2 документирует, но не создаёт свойства инфоблока.

## 6. Установка и migration

Migration history остаётся последовательной: существующий ID `2026_09_29_001_initial_hl_schema` не исполняется с новой schema и не переиспользуется. На fresh install он записывается как explicit historical baseline только после успешного read-only preflight, затем выполняется `2026_09_30_002_simplify_hl_schema`. На существующей v0.1 установке уже применённая 001 остаётся без изменений, и runner выполняет только 002. Перед baseline и перед любым удалением migration получает row count **всех** 12 legacy HL-блоков. Если хотя бы один непуст, операция завершается исключением со всеми найденными count до записи baseline и до первого schema write. Пустая legacy schema удаляется, после чего idempotent installer создаёт target schema.

Обычный uninstall сохраняет HL schema и данные. Удаление legacy schema — единственная destructive операция v0.2 и разрешено только после полного preflight.

## 7. Self-check

Для каждого target HL проверяются имя/table, общие поля и совместимость их metadata, UNIQUE/index. Для данных проверяются непустой `UF_XML_ID`, отсутствие дублей и `UF_PRICE >= 0`. Проверок связей между справочниками нет: таких связей в модели v0.2 нет.

## 8. Вне scope

Frontend, создание catalog properties, configurator, pricing formulas, basket/order, imports, stock, compatibility engine и admin CRUD не реализуются.
