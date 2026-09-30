# `kk.korsac` v0.2

Серверный Bitrix D7-модуль простых справочников KORSAC options. Требования: PHP 8.2+, Bitrix D7 `highloadblock`, поддерживаемый MySQL/MariaDB и права на создание индексов/удаление пустой legacy schema при migration.

## Schema

Создаются 12 HL: `KorsacCpu`, `KorsacGpu`, `KorsacMotherboard`, `KorsacRam`, `KorsacSsd`, `KorsacHdd`, `KorsacPsu`, `KorsacCooler`, `KorsacCase`, `KorsacOs`, `KorsacSoftware`, `KorsacService`. Все имеют один набор из 10 общих полей и индексы `UNIQUE(UF_XML_ID)` и `INDEX(UF_ACTIVE, UF_SORT)`. `UF_PRICE` — абсолютная цена варианта с `PRECISION=2`.

## Migration

История хранится в Bitrix `Option` (`kk.korsac/applied_migrations`). ID PR1 сохранён и никогда не запускается с v0.2 definition. На fresh install 001 отмечается как explicit historical baseline только после read-only preflight; существующая v0.1 установка сохраняет уже применённую 001. Migration `2026_09_30_002_simplify_hl_schema` подсчитывает строки во всех legacy blocks до baseline/schema writes. Любые данные блокируют migration с диагностикой всех непустых блоков. Только после успешного полного preflight удаляются пустые legacy HL и создаётся v0.2. Следующая migration `2026_09_30_003_price_precision` без пересоздания полей сохраняет их настройки и обеспечивает `UF_PRICE.PRECISION=2` во всех 12 HL. Повторные запуски безопасны:

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
```

`check` проверяет HL/table/fields/indexes, пустые или duplicate `UF_XML_ID` и отрицательную цену. Обычный uninstall сохраняет новую schema и данные.

## Read API

`OptionRepository::findByTypeAndXmlId($type, $xmlId)` принимает `CPU`, `GPU`, `MB`, `RAM`, `SSD`, `HDD`, `PSU`, `COOLER`, `CASE`, `OS`, `SOFTWARE`, `SERVICE`. Mapping фиксирован в `OptionTypeRegistry`.

## Tests

```bash
php local/modules/kk.korsac/tests/Unit/run.php
php local/modules/kk.korsac/tests/Integration/schema_v02_migration_smoke.php
php local/modules/kk.korsac/tests/Integration/smoke.php
php local/modules/kk.korsac/tests/Integration/acceptance_smoke.php
```

Migration smoke повторяем: на v0.2 он сообщает `already_migrated`; legacy data безопасно блокируют его. Acceptance создаёт RAM/HDD/OS/Software/Service fixtures, читает их repository, проверяет цену/self-check и удаляет только созданные строки в `finally`.

## Product configuration

Single-choice использует `*_DEFAULT` + `*_OPTIONS`; пустой default означает отсутствие варианта. Multiple-choice использует `*_MULTI_OPTIONS`. Модуль не создаёт catalog properties. Полный список: [`docs/architecture/KORSAC-PRODUCT-CONFIGURATION.md`](../../../docs/architecture/KORSAC-PRODUCT-CONFIGURATION.md).

## Ограничения

Интеграционные scripts требуют реальной Bitrix-среды. Bitrix UF не предоставляет переносимый DB default `now`, поэтому timestamps заполняет server-side write layer. Frontend, configurator, pricing formulas, catalog creation, basket/order, supplier/stock integrations, compatibility engine и admin CRUD не входят в PR2.
