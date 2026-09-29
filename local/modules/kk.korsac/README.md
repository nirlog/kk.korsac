# `kk.korsac`

Серверный Bitrix D7-модуль доменных данных бренда KORSAC. Версия 0.1 (PR1) создаёт только инфраструктуру Highload-блоков, миграции, диагностику схемы и минимальный read repository.

## Требования

- PHP 8.2+;
- 1C-Битрикс D7 с модулем `highloadblock`;
- поддерживаемый Битриксом MySQL/MariaDB;
- права БД на `CREATE INDEX`.

Разместите каталог `kk.korsac` в `local/modules/`, затем установите модуль в стандартном интерфейсе модулей Битрикс. Bootstrap регистрирует namespace `KK\Korsac` по абсолютному пути, вычисленному от `include.php`, поэтому install-time autoload не зависит от выбора module holder (`local/modules` или `bitrix/modules`). Каталог KORSAC и `IBLOCK_ID` не требуются.

## Модель данных

Установка создаёт 12 отдельных HL-блоков:

- component classes: `KorsacCpuClass`, `KorsacGpuClass`, `KorsacMotherboardClass`, `KorsacRamClass`, `KorsacSsdClass`, `KorsacPsuClass`, `KorsacCoolerClass`, `KorsacCaseClass`, `KorsacServiceClass`;
- registry: `KorsacPhysicalSku`;
- закупки: `KorsacSupplierOffer`;
- проверенные компактные конфигурации: `KorsacValidatedBuild`.

Связи используют строковый `UF_XML_ID`, а не внутренние числовые ID. Реестр ComponentClass дополнительно поддерживает `SERVICE`, тогда как Physical SKU намеренно допускает только `CPU`, `GPU`, `MB`, `RAM`, `SSD`, `PSU`, `COOLER`, `CASE`. `SchemaDefinition` — единый источник описаний блоков, полей и SQL-индексов.

## Миграции и индексы

Первая миграция имеет ID `2026_09_29_001_initial_hl_schema`. Список успешно применённых миграций хранится через Bitrix `Option` (`kk.korsac/applied_migrations`). Отметка записывается только после успешного выполнения.

Installer сверяет стабильные имена блоков и таблиц, добавляет только отсутствующие поля и не исправляет несовместимые поля автоматически. Стандартные Bitrix `string` UF на актуальном ядре хранятся как SQL `TEXT`, поэтому все индексируемые строковые коды имеют `MAX_LENGTH`, а SQL-индексы используют такой же ограниченный prefix (`UF_XML_ID(128)` и аналогично для остальных кодов). Индексы сверяются по имени, порядку колонок, prefix length и признаку UNIQUE. Перед созданием UNIQUE проверяются полные значения строк; данные не удаляются и не исправляются.

Повторный запуск и проверка непосредственно из CLI в корне сайта Битрикс (скрипты сами синхронизируют `$_SERVER['DOCUMENT_ROOT']` до загрузки ядра):

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

Install smoke запускается только на тестовом сайте, где `kk.korsac` ещё не зарегистрирован. При нарушении precondition он завершается без изменений; при успехе устанавливает модуль и намеренно оставляет его зарегистрированным, не удаляя HL data:

```bash
php local/modules/kk.korsac/tests/Integration/install_smoke.php
```

Обычный smoke запускается после установки. Он дважды выполняет idempotent schema installer и затем self-check:

```bash
php local/modules/kk.korsac/tests/Integration/smoke.php
```

При обычном CLI-запуске из checkout скрипты вычисляют document root относительно собственного расположения и устанавливают `$_SERVER['DOCUMENT_ROOT']` до подключения `prolog_before.php`; wrapper для этого не требуется.

## Удаление

Обычный uninstall только отменяет регистрацию модуля. HL-блоки и их строки сохраняются. PR1 намеренно не предоставляет автоматическое полное удаление данных.

## Не входит в PR1

Нет frontend/UI, каталогов и catalog properties, конфигуратора, корзины/заказов, pricing formulas, интеграции `kk.price-update`, supplier imports, автоматического выбора SKU, универсального compatibility engine и изменений King-Komp/`kk-template`.

## Ограничения

- Интеграционный smoke-тест требует настоящую инициализированную среду Битрикс и БД.
- Значение `now` в декларации описывает server-side audit default семантически; Bitrix user-field metadata не предоставляет переносимый DB-default для текущего времени. Заполнять audit timestamps должен серверный write layer (он не входит в PR1).
- Проверка `UF_COMPONENTS_JSON` трактует строковые значения JSON-ключей, содержащих `sku`, как stable Physical SKU codes; JSON предназначен для snapshot whitelist с такими кодами.
