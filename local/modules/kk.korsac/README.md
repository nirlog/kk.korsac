# `kk.korsac`

Серверный Bitrix D7-модуль доменных данных бренда KORSAC. Версия 0.1 (PR1) создаёт только инфраструктуру Highload-блоков, миграции, диагностику схемы и минимальный read repository.

## Требования

- PHP 8.2+;
- 1C-Битрикс D7 с модулем `highloadblock`;
- поддерживаемый Битриксом MySQL/MariaDB;
- права БД на `CREATE INDEX`.

Разместите каталог `kk.korsac` в `local/modules/`, затем установите модуль в стандартном интерфейсе модулей Битрикс. Каталог KORSAC и `IBLOCK_ID` не требуются.

## Модель данных

Установка создаёт 12 отдельных HL-блоков:

- component classes: `KorsacCpuClass`, `KorsacGpuClass`, `KorsacMotherboardClass`, `KorsacRamClass`, `KorsacSsdClass`, `KorsacPsuClass`, `KorsacCoolerClass`, `KorsacCaseClass`, `KorsacServiceClass`;
- registry: `KorsacPhysicalSku`;
- закупки: `KorsacSupplierOffer`;
- проверенные компактные конфигурации: `KorsacValidatedBuild`.

Связи используют строковый `UF_XML_ID`, а не внутренние числовые ID. `SchemaDefinition` — единый источник описаний блоков, полей и SQL-индексов.

## Миграции и индексы

Первая миграция имеет ID `2026_09_29_001_initial_hl_schema`. Список успешно применённых миграций хранится через Bitrix `Option` (`kk.korsac/applied_migrations`). Отметка записывается только после успешного выполнения.

Installer сверяет стабильные имена блоков и таблиц, добавляет только отсутствующие поля и не исправляет несовместимые поля автоматически. Индексы сверяются по имени, порядку колонок и признаку UNIQUE. Перед созданием UNIQUE проверяются дубликаты; данные не удаляются и не исправляются.

Повторный запуск и проверка из корня сайта Битрикс:

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
```

`check` возвращает JSON и проверяет структуру, индексы, дубли `UF_XML_ID`, отрицательные цены и ссылки Physical SKU → class, Supplier Offer → Physical SKU, Validated Build → Physical SKU.

## Read API

`ComponentClassRepository::findByTypeAndXmlId($type, $xmlId)` принимает `CPU`, `GPU`, `MB`, `RAM`, `SSD`, `PSU`, `COOLER`, `CASE` или `SERVICE` и возвращает строку HL-блока либо `null`. Формулы цены в repository отсутствуют.

## Тестирование

Pure-PHP тесты не требуют Битрикс:

```bash
php local/modules/kk.korsac/tests/Unit/run.php
```

Smoke-тест запускается в установленном сайте Битрикс. Он дважды выполняет idempotent schema installer и затем self-check:

```bash
php local/modules/kk.korsac/tests/Integration/smoke.php
```

Перед smoke-тестом задайте `DOCUMENT_ROOT`, если текущий каталог не является корнем сайта.

## Удаление

Обычный uninstall только отменяет регистрацию модуля. HL-блоки и их строки сохраняются. PR1 намеренно не предоставляет автоматическое полное удаление данных.

## Не входит в PR1

Нет frontend/UI, каталогов и catalog properties, конфигуратора, корзины/заказов, pricing formulas, интеграции `kk.price-update`, supplier imports, автоматического выбора SKU, универсального compatibility engine и изменений King-Komp/`kk-template`.

## Ограничения

- Интеграционный smoke-тест требует настоящую инициализированную среду Битрикс и БД.
- Значение `now` в декларации описывает server-side audit default семантически; Bitrix user-field metadata не предоставляет переносимый DB-default для текущего времени. Заполнять audit timestamps должен серверный write layer (он не входит в PR1).
- Проверка `UF_COMPONENTS_JSON` трактует строковые значения JSON-ключей, содержащих `sku`, как stable Physical SKU codes; JSON предназначен для snapshot whitelist с такими кодами.
