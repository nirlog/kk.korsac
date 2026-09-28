# KK KORSAC — PR1 Codex Task

Дата: 2026-09-29

Статус: готово к реализации.

Основание:

- `docs/architecture/KORSAC-HL-TECHNICAL-SPEC.md`
- canonical product/data architecture in `nirlog/kk-template`:
  - `docs/architecture/KORSAC-COMPONENTS-HL-SCHEMA.md`
  - `docs/product/KORSAC-MINI-ARCHITECTURE.md`

## 1. Цель PR

Создать первый инфраструктурный PR для серверного Bitrix D7-модуля `kk.korsac`.

PR должен дать минимальный, но рабочий фундамент доменной модели KORSAC:

1. каркас устанавливаемого модуля;
2. idempotent installer/migration layer;
3. создание KORSAC Highload-блоков и user fields по технической спецификации;
4. SQL indexes / unique constraints;
5. schema self-check;
6. базовый read API для получения component class по типу и `UF_XML_ID`;
7. тесты критической логики.

PR не должен содержать frontend, каталог, корзину, checkout, UI конфигуратора или интеграцию с `kk-template`.

## 2. Целевой модуль

Module ID:

```text
kk.korsac
```

Namespace:

```php
KK\Korsac
```

Целевая среда нового проекта:

- 1C-Bitrix D7;
- PHP 8.2+;
- MySQL/MariaDB, совместимая с поддерживаемой версией Bitrix.

Не использовать legacy CModule-архитектуру внутри бизнес-логики, кроме обязательного Bitrix install entry point.

## 3. Рекомендуемая структура

Допустимы небольшие изменения структуры, если они обоснованы, но не усложнять модуль без необходимости.

```text
local/modules/kk.korsac/
├── include.php
├── install/
│   ├── index.php
│   └── version.php
├── lib/
│   ├── Install/
│   │   ├── SchemaDefinition.php
│   │   ├── SchemaInstaller.php
│   │   ├── MigrationRunner.php
│   │   └── MigrationInterface.php
│   ├── Health/
│   │   └── SchemaSelfCheck.php
│   ├── Repository/
│   │   └── ComponentClassRepository.php
│   └── Exception/
├── tests/
└── README.md
```

Не вводить DI-container, ORM-слой поверх Bitrix ORM, CQRS, event sourcing или универсальный migration framework.

## 4. HL-блоки PR1

Installer должен создать все сущности из `KORSAC-HL-TECHNICAL-SPEC.md`:

```text
KorsacCpuClass
KorsacGpuClass
KorsacMotherboardClass
KorsacRamClass
KorsacSsdClass
KorsacPsuClass
KorsacCoolerClass
KorsacCaseClass
KorsacServiceClass
KorsacPhysicalSku
KorsacSupplierOffer
KorsacValidatedBuild
```

DB table names должны задаваться явно и совпадать со спецификацией.

Нельзя подменять отдельные class HL-блоки одним универсальным `KorsacComponent`.

## 5. SchemaDefinition

Описание HL schema должно находиться в одном декларативном месте, а не быть размазано по installer-коду.

`SchemaDefinition` должен описывать минимум:

- HL name;
- DB table name;
- user fields;
- required/default/multiple settings;
- индексы;
- unique constraints.

Installer и self-check должны использовать одну и ту же schema definition, чтобы не дублировать правила.

## 6. Idempotent installation

Повторный запуск schema installer не должен:

- создавать дубликаты HL-блоков;
- создавать дубликаты полей;
- создавать дубликаты индексов;
- терять данные;
- молча менять тип существующего поля;
- менять существующий `UF_XML_ID`.

Алгоритм для каждой сущности:

1. найти HL block по стабильному имени/table name;
2. создать, если отсутствует;
3. проверить поля;
4. добавить отсутствующие безопасные поля;
5. если существующее поле несовместимо со schema definition — вернуть явную ошибку migration/schema mismatch;
6. проверить/create indexes;
7. продолжить установку следующей сущности.

Любые destructive schema changes в PR1 запрещены.

## 7. Stable identifiers

`UF_XML_ID` — основной стабильный идентификатор записи.

Требования:

- `string(128)`;
- required;
- UNIQUE внутри своего HL-блока;
- не используется internal numeric `ID` как внешний stable reference между сущностями.

Связи Physical SKU / Supplier Offer / Validated Build должны строиться через stable string codes согласно технической спецификации.

## 8. Индексы

После создания Bitrix user fields installer должен обеспечить SQL indexes и UNIQUE constraints, описанные в `KORSAC-HL-TECHNICAL-SPEC.md`.

Требования:

- проверять существование индекса перед созданием;
- имена индексов должны быть стабильными и предсказуемыми;
- повторная миграция не должна падать из-за уже существующего индекса;
- создание UNIQUE не должно молча удалять/исправлять конфликтующие данные — при конфликте вернуть диагностическую ошибку.

## 9. Migration layer

Нужен минимальный versioned migration mechanism для будущего развития schema.

PR1 должен содержать первую миграцию, например:

```text
2026_09_29_001_initial_hl_schema
```

Migration runner должен уметь:

- определить, выполнялась ли migration;
- выполнить ещё не применённую migration;
- записать факт успешного выполнения;
- безопасно пережить повторный запуск.

Не требуется rollback для destructive migrations в PR1.

Если для migration history нужен отдельный HL-блок/Option storage, выбрать простейший устойчивый Bitrix-native вариант и описать решение в README.

## 10. Schema self-check

`SchemaSelfCheck` должен работать отдельно от installer и возвращать структурированный результат, пригодный для CLI/admin UI в будущем.

Минимум проверок:

- существуют все HL-блоки;
- совпадают DB table names;
- существуют обязательные поля;
- тип/MULTIPLE обязательных полей соответствует schema;
- присутствуют UNIQUE/indexes;
- Physical SKU не ссылается на несуществующий component class;
- Supplier Offer не ссылается на несуществующий Physical SKU;
- Validated Build не содержит broken references;
- `UF_PRICE` component class не отрицателен;
- duplicate stable codes отсутствуют.

Пример результата:

```php
[
    'ok' => false,
    'errors' => [...],
    'warnings' => [...],
    'checkedAt' => '...'
]
```

Не завязывать self-check на HTML.

## 11. ComponentClassRepository

Добавить минимальный read API, который позже смогут использовать pricing layer и другие сервисы.

Нужен метод концептуально такого уровня:

```php
findByTypeAndXmlId(string $componentType, string $xmlId): ?array
```

или эквивалент с DTO.

Поддерживаемые component types:

```text
CPU
GPU
MB
RAM
SSD
PSU
COOLER
CASE
SERVICE
```

Минимально возвращаем:

- stable XML ID;
- public/internal name;
- active;
- price;
- type-specific fields при необходимости.

Не добавлять pricing formulas в этот repository.

## 12. Uninstall policy

Обычный uninstall модуля должен:

- unregister module/code;
- сохранить KORSAC HL-блоки и данные.

Удаление данных запрещено по умолчанию.

Полное удаление schema может быть подготовлено как отдельный explicit developer/service action, но не должно выполняться обычным uninstall.

## 13. Catalog properties

В PR1 **не создавать каталог и не требовать IBLOCK_ID**.

Допустимо подготовить сервис/метод для будущего создания catalog properties, но не вызывать его автоматически без явно переданной конфигурации.

PR1 должен нормально устанавливаться на чистой Bitrix-установке без каталога KORSAC.

## 14. Что НЕ входит в PR1

Не делать:

- frontend;
- шаблон сайта;
- TypeScript/JS configurator;
- Bitrix catalog product creation;
- basket/order integration;
- pricing formulas/configuration price calculation;
- `kk.price-update` integration;
- supplier API imports;
- автоматический выбор physical SKU;
- универсальный compatibility rule engine;
- admin CRUD UI для компонентов;
- KORSAC SYSTEM ID UI;
- benchmark/test UI;
- изменение King-Komp или его HL-блоков.

## 15. Tests

Добавить тесты там, где код можно проверить без полноценного web UI.

Минимум:

1. schema definition содержит все ожидаемые entities;
2. все component class entities содержат common fields;
3. stable table/name mapping корректен;
4. migration runner не запускает уже применённую migration повторно;
5. self-check корректно классифицирует хотя бы несколько synthetic broken-reference cases;
6. component type registry отклоняет неизвестный type.

Если полноценные Bitrix integration tests нельзя стабильно запускать вне Bitrix, разделить:

- unit tests pure-PHP частей;
- integration smoke test script для запуска внутри Bitrix.

Не имитировать полноценный Bitrix ORM большим самописным mock-framework.

## 16. README

README первого PR должен содержать:

- назначение модуля;
- требования;
- установку;
- структуру HL data model;
- как повторно запустить migration/schema check;
- uninstall/data-preservation policy;
- как запустить tests/smoke check;
- явный список того, что PR1 пока не реализует.

## 17. Acceptance criteria

PR готов к merge только если:

1. модуль устанавливается на чистой Bitrix-установке;
2. создаются все 12 HL-блоков;
3. DB table names совпадают со spec;
4. обязательные fields создаются с корректными типами/flags;
5. `UF_XML_ID` защищён UNIQUE constraint во всех нужных сущностях;
6. schema installation/migration повторно запускается без побочных эффектов;
7. можно создать обычный ATX component class;
8. можно создать MINI component classes;
9. к одному component class можно связать несколько approved Physical SKU;
10. для одного Physical SKU можно записать несколько Supplier Offer;
11. можно сохранить validated build profile `KORSAC PLAY 1440 MINI`;
12. self-check на корректной schema возвращает `ok=true`;
13. self-check обнаруживает broken references;
14. uninstall без explicit remove-data сохраняет HL data;
15. ComponentClassRepository может получить class по `componentType + UF_XML_ID`;
16. текущий King-Komp и `kk-template` не изменяются runtime-кодом этого PR;
17. отсутствует frontend/UI/pricing configurator scope creep;
18. README позволяет повторить установку и smoke validation.

## 18. Результат PR

После merge у нас должен быть отдельный устойчивый серверный слой KORSAC, на который далее можно по независимым PR подключать:

1. catalog properties/default + `_OPTIONS`;
2. pricing integration с `kk.price-update`;
3. approved SKU management;
4. MINI compatibility/validated builds;
5. cart/order snapshot;
6. KORSAC SYSTEM ID;
7. frontend `kk-template`.

## 19. Инструкция Codex

Перед кодированием:

1. прочитать `AGENTS.md`;
2. прочитать `docs/architecture/KORSAC-HL-TECHNICAL-SPEC.md` полностью;
3. не менять schema semantics без явного объяснения в PR;
4. если Bitrix API не позволяет реализовать конкретный constraint буквально так, как описано, выбрать минимально отличающийся безопасный вариант и описать расхождение;
5. не расширять scope;
6. не рефакторить существующие King-Komp modules;
7. не добавлять зависимость от `kk-template`.

В описании PR обязательно перечислить:

- созданные HL-блоки;
- migration strategy;
- index/unique strategy;
- self-check capabilities;
- tests;
- известные ограничения;
- manual smoke-test steps.
