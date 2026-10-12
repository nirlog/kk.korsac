# `kk.korsac` v0.10

Серверный Bitrix D7-модуль простых справочников KORSAC options. Требования: PHP 8.2+, Bitrix D7 `highloadblock`, поддерживаемый MySQL/MariaDB и права на создание индексов/удаление пустой legacy schema при migration.

## Schema

Создаются 12 HL: `KorsacCpu`, `KorsacGpu`, `KorsacMotherboard`, `KorsacRam`, `KorsacSsd`, `KorsacHdd`, `KorsacPsu`, `KorsacCooler`, `KorsacCase`, `KorsacOs`, `KorsacSoftware`, `KorsacService`. Все имеют один набор из 10 общих полей и индексы `UNIQUE(UF_XML_ID)` и `INDEX(UF_ACTIVE, UF_SORT)`. `UF_PRICE` — абсолютная цена варианта с `PRECISION=2`.

## Migration

История хранится в Bitrix `Option` (`kk.korsac/applied_migrations`). ID PR1 сохранён и никогда не запускается с v0.2 definition. На fresh install 001 отмечается как explicit historical baseline только после read-only preflight; существующая v0.1 установка сохраняет уже применённую 001. Migration `2026_09_30_002_simplify_hl_schema` подсчитывает строки во всех legacy blocks до baseline/schema writes. Любые данные блокируют migration с диагностикой всех непустых блоков. Только после успешного полного preflight удаляются пустые legacy HL и создаётся v0.2. Migration `2026_09_30_003_price_precision` без пересоздания полей сохраняет их настройки и обеспечивает `UF_PRICE.PRECISION=2`; migration `2026_10_03_005_option_image` безопасно добавляет optional `UF_IMAGE` во все 12 HL. Повторные запуски безопасны:

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
node local/modules/kk.korsac/tests/Frontend/run.js
node local/modules/kk.korsac/tests/Frontend/renderer.run.js
php local/modules/kk.korsac/tests/Integration/schema_v02_migration_smoke.php
php local/modules/kk.korsac/tests/Integration/smoke.php
php local/modules/kk.korsac/tests/Integration/acceptance_smoke.php
```

Migration smoke повторяем: на v0.2 он сообщает `already_migrated`; legacy data безопасно блокируют его. Acceptance создаёт RAM/HDD/OS/Software/Service fixtures, читает их repository, проверяет цену/self-check и удаляет только созданные строки в `finally`.

## Product configuration

Single-choice использует `*_DEFAULT` + `*_OPTIONS`; пустой default означает отсутствие варианта. Multiple-choice использует `*_MULTI_OPTIONS`. 22 directory properties устанавливаются только для явно переданного каталога и никогда не создаются `DoInstall()`:

```bash
php local/modules/kk.korsac/tools/catalog.php install-properties --iblock=<ID>
php local/modules/kk.korsac/tools/catalog.php check-properties --iblock=<ID>
```

`ProductConfigurationRepository` читает только канонические KORSAC properties,
сохраняет их порядок, проверяет ссылки и возвращает нормализованные identifiers
без цен. Полный список и smoke-команды: [`docs/architecture/KORSAC-PRODUCT-CONFIGURATION.md`](../../../docs/architecture/KORSAC-PRODUCT-CONFIGURATION.md).


## Configuration pricing

Pricing layer валидирует `ConfigurationSelection` строго по whitelist из
`ProductConfiguration`. `HlOptionPriceProvider` остаётся raw provider: hardware
`UF_PRICE` является procurement cost, а OS/SOFTWARE/SERVICE уже direct retail.
`RetailOptionPriceProvider` применяет configured markup только к hardware.
Деньги после границы HL представлены только целыми minor units (копейками).
Цена DEFAULT-конфигурации передаётся `ConfigurationPriceCalculator` извне как
`basePriceMinor`: этот calculator не выводит её из options и считает только
delta пользовательского выбора. Модуль читает явно configured Catalog price type, но не обновляет Catalog price
и не применяет скидки. Весь pricing path read-only.

```bash
php local/modules/kk.korsac/tests/Integration/configuration_pricing_smoke.php \
  --iblock=2 --product=4 --base-price-minor=15000000

php local/modules/kk.korsac/tests/Integration/configuration_pricing_smoke.php \
  --iblock=2 --product=4 --base-price-minor=15000000 \
  --selection-json='{"HDD":"HDD_2TB","SOFTWARE":["SOFTWARE_OFFICE"]}'
```

Подробности и формулы: [`KORSAC-CONFIGURATION-PRICING.md`](../../../docs/architecture/KORSAC-CONFIGURATION-PRICING.md).

```bash
php local/modules/kk.korsac/tools/pricing.php configure \
  --iblock=2 --channel=RETAIL --price-type=1 \
  --markup-bps=2000 --fixed-adjustment-minor=0
php local/modules/kk.korsac/tools/pricing.php show --iblock=2 --channel=RETAIL
```

The same canonical settings are available to authorized administrators at
**Services → KORSAC → Ценообразование**. Both channels may be configured or
explicitly disabled; CLI and admin changes are immediately interoperable. See
[`KORSAC-PRICING-ADMIN.md`](../../../docs/architecture/KORSAC-PRICING-ADMIN.md).
For a module that was already installed before version 0.6.0, follow that
document's **Updating an existing installation** step to copy the new admin
entry point into `/bitrix/admin`.

Bitrix `BASE=Y` не является KORSAC price-selection rule. Browser не может
выбирать RETAIL/BUSINESS, price type или policy. До отдельного обновления
`kk.price-update` Catalog prices могут временно отражать старую формулу;
`kk.korsac` не переписывает их автоматически.

## Default component cost

`DefaultConfigurationCostCalculator` вычисляет `DEFAULT_COMPONENT_COST` как
сумму абсолютных цен только `default` всех single-групп. Пустой default даёт
ноль без обращения к provider; alternatives, `SOFTWARE` и `SERVICE` не входят
в сумму. Расчёт использует `OptionPriceProviderInterface`, поэтому с
`HlOptionPriceProvider` автоматически работает существующий request-level
cache. Результат содержит breakdown всех 12 групп и `totalMinor`.

`DEFAULT_COMPONENT_COST` не является Bitrix Catalog price: будущая pricing
policy может добавить к нему margin и adjustments, чтобы получить
`BASE_CATALOG_PRICE`; итоговый пользовательский выбор затем изменяет base price
через `ConfigurationPriceCalculator` delta. Calculator ничего не записывает.

```bash
php local/modules/kk.korsac/tests/Integration/default_configuration_cost_smoke.php \
  --iblock=2 --product=4
```

Архитектура и границы ответственности описаны в
[`KORSAC-DEFAULT-CONFIGURATION-COST.md`](../../../docs/architecture/KORSAC-DEFAULT-CONFIGURATION-COST.md).

## Ограничения

Интеграционные scripts требуют реальной Bitrix-среды. Bitrix UF не предоставляет переносимый DB default `now`, поэтому timestamps заполняет server-side write layer. Catalog-detail/product-gallery integration, final storefront design, catalog base-price recalculation, full checkout/payment/delivery, supplier/stock integrations, compatibility engine и admin CRUD не входят в scope этого pricing layer. Configured Basket integration and immutable order references are implemented separately from the pricing layer.

## Public Configurator API v1

Read-only storefront actions `kk:korsac.Configurator.get` (GET) and
`kk:korsac.Configurator.calculate` (POST) always read the current product
whitelist, retail-normalized option prices and the server-configured RETAIL
Catalog price type. The browser cannot choose a channel or price type. Public payloads contain deltas but never `UF_PRICE` or
absolute component prices.

```javascript
BX.ajax.runAction('kk:korsac.Configurator.get', {
    method: 'GET',
    getParameters: {iblockId: 2, productId: 4}
});

BX.ajax.runAction('kk:korsac.Configurator.calculate', {
    method: 'POST',
    data: {
        iblockId: 2,
        productId: 4,
        selection: {HDD: '2 ТБ, 5400 rpm', SOFTWARE: ['Microsoft Office (Trial)']}
    }
});
```

Architecture, exposure rules, errors and smoke instructions:
[`KORSAC-CONFIGURATOR-API.md`](../../../docs/architecture/KORSAC-CONFIGURATOR-API.md).


## Storefront-neutral frontend core

The `kk.korsac.configurator-core` Bitrix extension provides an unstyled,
template-independent API/state adapter for the three public actions. It retains
only product identity and selection; displayed prices remain transient server
projections, and Cart independently calculates the authoritative price and
immutable snapshot. See
[`KORSAC-FRONTEND-CONFIGURATOR-CORE.md`](../../../docs/architecture/KORSAC-FRONTEND-CONFIGURATOR-CORE.md).

## Storefront-neutral DOM renderer

The optional `kk.korsac.configurator-renderer` extension adds a plain-JavaScript
DOM view over the headless core. It supports every public presentation mode,
server-authoritative prices, debounced race-safe calculation, accessible
loading/error/Add states and neutral responsive CSS. Existing installations
must rerun `InstallFiles()` to copy it into `/local/js`; schema reinstallation
is not required. API, browser harness and acceptance steps are documented in
[`KORSAC-FRONTEND-CONFIGURATOR-RENDERER.md`](../../../docs/architecture/KORSAC-FRONTEND-CONFIGURATOR-RENDERER.md).

## Configured Basket and immutable order snapshot

`kk:korsac.Cart.add` accepts only `iblockId`, `productId`, and a partial `selection`. It shares `ConfiguredProductPricingService` with Configurator, creates a schema-v1 immutable snapshot in `b_kk_korsac_config_snapshot`, and adds a separate guest-FUSER Basket line at the authoritative custom unit price. Compact snapshot references and human-readable configuration properties flow to Order through standard Sale behavior. The `sale` module is required only when Cart is invoked.

Apply the new table migration to an installed environment with `php local/modules/kk.korsac/tools/schema.php migrate`. See [`KORSAC-CART-SNAPSHOT.md`](../../../docs/architecture/KORSAC-CART-SNAPSHOT.md) for the persistence/property contract, compensation behavior, dry smoke, controlled write smoke, and limitations. Normal uninstall retains snapshots.


## Product-card configurator component

`kk:korsac.configurator` mounts the existing renderer from a normal Bitrix product
page. Canonical source is installed from `install/components` to
`/local/components`; existing installations should rerun `InstallFiles()` and do
not need a schema migration. Parameters, integration example and fixture #367
acceptance are documented in
[`KORSAC-PRODUCT-CONFIGURATOR-COMPONENT.md`](../../../docs/architecture/KORSAC-PRODUCT-CONFIGURATOR-COMPONENT.md).


## Physical System core

v0.9 adds `b_kk_korsac_system`, `b_kk_korsac_system_sequence` and
`b_kk_korsac_system_component` in migration `2026_10_12_006_system_core`.
Existing 0.8 installations run `tools/schema.php migrate`; `tools/schema.php check`
now also verifies the System tables and indexes without weakening HL checks.
Normal uninstall preserves all System/component/sequence business data.

`SystemCreationService` explicitly creates one physical PC order unit with a
server-generated immutable `KS-YYMM-NNNN` identifier, NULL owner and ACTIVE
lifecycle. A three-column order-unit UNIQUE constraint makes creation idempotent.
The monthly sequence uses its own D7 mysqli connection and an InnoDB locked-row
transaction, with a strict 9999 ceiling and no number reuse. The System may
reference the existing immutable configuration snapshot; actual installed
components and their serial numbers/history are separate internal records.
Child records use `SYSTEM_INTERNAL_ID` (`SystemComponent::$systemInternalId`) for
numeric System.ID; `SYSTEM_ID` always denotes the human KS identifier. Ordinary
System ORM updates allow only USER_ID, LIFECYCLE_STATE and UPDATED_AT, preserving
all creation-time source fields. System-core identity/reference
columns use signed INT and positive D7-compatible IDs `1..2147483647`; source
IDs above that range are rejected before sequence allocation. The earlier live
review build exposed unsigned/D7 coercion; the corrected schema must pass a
live retest on the standalone test server before merge. An already-applied
unsigned draft of migration 006 requires disposable test DB reset or an explicit
data-preserving schema correction; migrate alone does not rewrite it.

```bash
php local/modules/kk.korsac/tests/Integration/system_core_smoke.php
# Dedicated test Bitrix DB only: fixtures roll back; sequence numbers remain consumed.
php local/modules/kk.korsac/tests/Integration/system_core_smoke.php --confirm-write --concurrency=4
```

The default smoke is read-only. Full contracts, migration, privacy, business
timezone and manual validation are in
[`KORSAC-SYSTEM-DOMAIN.md`](../../../docs/architecture/KORSAC-SYSTEM-DOMAIN.md).
Passport, warranty, service workflow, ownership, inquiries and System Admin UI
remain future stages; Sale synchronization and production status/history are
implemented in v0.10. All persistence
belongs to standalone KORSAC, with no King-Komp site/database integration.


## Sale → System automation and production status

v0.10 adds migration `2026_10_12_007_system_sale_automation` with separate current
production status/history tables. Migration 006/System core is unchanged.
`schema.php migrate` also ensures one persistent `sale/OnSaleOrderSaved` handler;
normal uninstall removes executable registration and retains all business data.
Health now covers HL, existing snapshots, System core, production schema and
handler registration.

Automation defaults to disabled. Configure the exact real Sale ORDER STATUS_ID;
never infer it from the translated label «Согласован». Eligible PC rows require
MODULE=kk.korsac and KORSAC_CONFIGURED=Y with validated immutable snapshot metadata.
Each positive whole quantity unit (maximum 1000 per line) maps to one System.
All lines and existing-unit conflicts are preflighted before number allocation.
Order edits never delete/cancel/reassign Systems. Retry repairs missing initial
WAREHOUSE status and preserves advanced production state. Status codes/history
are independent from lifecycle and ownership.

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tools/system.php sale-config show
php local/modules/kk.korsac/tools/system.php sale-config set --status=<ORDER_STATUS_ID>
php local/modules/kk.korsac/tools/system.php inspect-order --order=<ORDER_ID>
php local/modules/kk.korsac/tools/system.php sync-order --order=<ORDER_ID>
php local/modules/kk.korsac/tools/system.php status-set --system=<KS-YYMM-NNNN> --status=ASSEMBLY
php local/modules/kk.korsac/tests/Integration/system_sale_automation_smoke.php
# Actual dedicated test Order only: persists Systems/history, no Order edits.
php local/modules/kk.korsac/tests/Integration/system_sale_automation_smoke.php --order=<TEST_ORDER_ID> --confirm-write
```

Manual sync uses the same service as automation and requires the configured
trigger even when automatic execution is disabled; there is no force mode.
Labels can be overridden with `system.php status-label set` without changing codes.
Event failures log only order ID/safe code and do not re-save or fail the saved
Order. Details, operational commands and outstanding live Sale checks are in
[`KORSAC-SYSTEM-SALE-AUTOMATION.md`](../../../docs/architecture/KORSAC-SYSTEM-SALE-AUTOMATION.md).
Passport, warranty, ownership, service, public API, System Admin UI and frontend
remain future work. No King-Komp runtime integration is introduced.
