# `kk.korsac` v0.15

Серверный Bitrix D7-модуль простых справочников KORSAC options. Требования: PHP 8.2+, Bitrix D7 `highloadblock`, поддерживаемый MySQL/MariaDB и права на создание индексов/удаление пустой legacy schema при migration.

v0.14 adds authoritative actual physical component install/expected-ID replace,
stable slots, immutable removed history, parent-row locking and private serial
validation. It uses the existing component table; migration 010 only validates
and adopts compatible legacy rows. Public Passport keeps its exact current
three-field component projection. See [KORSAC-SYSTEM-COMPONENTS.md](../../../docs/architecture/KORSAC-SYSTEM-COMPONENTS.md).

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
System ORM updates now allow only LIFECYCLE_STATE and UPDATED_AT; v0.13 ownership
claims exclusively control USER_ID, preserving
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
Warranty duration/claims, service workflow, ownership transfer,
inquiries and System Admin UI remain future stages; Sale synchronization and
production status/history are implemented in v0.10, Passport backend and
handoff-based warranty start in v0.11. All persistence
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
Passport backend and warranty start are added in v0.11 below, public HTTP in
v0.12. Warranty duration/claims, ownership transfer, service, System Admin UI and final storefront
remain future work. No King-Komp runtime integration is introduced.


## System Passport Core v1

v0.11 / migration `2026_10_13_008_system_passport_core` adds a separate
`b_kk_korsac_system_passport` table; migrations 001..007 are unchanged.
`schema.php migrate` prepares it, `schema.php check` adds Passport health without
weakening previous checks, and normal uninstall preserves identities, secret
digests, handoff and warranty-start history.

Preparation locks the ACTIVE parent System and idempotently issues random 128-bit
PUBLIC_ID and independent SECRET_CODE. Only SHA-256 SECRET_CODE_HASH is stored.
**The explicit passport-prepare CLI output contains the raw secret once and is
sensitive; do not put it in logs/chat/PR/URLs/QR.** Repeated prepare returns null
secret with the same Passport. Physical handoff is a separate irreversible
activation: TRANSFERRED_AT equals WARRANTY_STARTED_AT in v1, with no duration/end
calculation and no production READY requirement/transition or owner assignment.

```bash
php local/modules/kk.korsac/tools/system.php passport-prepare --system=<TEST_SYSTEM>
php local/modules/kk.korsac/tools/system.php passport-show --system=<TEST_SYSTEM>
php local/modules/kk.korsac/tools/system.php passport-activate --system=<TEST_SYSTEM>
php local/modules/kk.korsac/tools/system.php passport-public --public-id=<PUBLIC_ID>
php local/modules/kk.korsac/tests/Integration/system_passport_smoke.php
# Architecture-reviewed fresh dedicated test System only; persists Passport/activation.
php local/modules/kk.korsac/tests/Integration/system_passport_smoke.php --system=<TEST_SYSTEM> --confirm-write
```

PREPARED/unknown/malformed public lookups return the same not-found result. Only
ACTIVE resolves to the explicit DTO, with human SYSTEM_ID, model/type, dates and
actual active component type/slot label/name. Numeric identities, Orders/Users,
credentials/digests, snapshots, serials/internal slot keys and histories stay
private. Core v1 reserved `/passport/{PUBLIC_ID}/`; v0.12 implements its HTTP
delivery separately below. Passport verification alone grants no ownership;
v0.13 adds the separate claim authority below. QR images, public claim UI,
transfer/reissue/service/Admin UI and warranty duration remain future work.

Full contracts, timestamp precision, collision handling, safe commands and
required live gate: [`KORSAC-SYSTEM-PASSPORT.md`](../../../docs/architecture/KORSAC-SYSTEM-PASSPORT.md).
Live Passport checks require the standalone Bitrix test server and human
architecture review before writes/merge; pure tests do not close that gate.


## Public Passport HTTP delivery v1

v0.12 adds `kk:korsac.passport` and `/passport/{PUBLIC_ID}/`, backed only by the
existing public DTO/query service. ACTIVE is HTTP 200; malformed/unknown/PREPARED
is the same generic 404; operational/unexpected failures are generic 500.
Responses use noindex/nofollow/noarchive, no-referrer and no-store. The minimal
Russian page escapes dynamic content, supports phones/print and requires no JS.
No secret, hash, serial, numeric identity or Order/User data enters delivery.

Fresh installation registers the narrow Bitrix rewrite and copies only proven
module-owned Passport files. Existing installations upgrade without reinstall
or migration 009:

```bash
php local/modules/kk.korsac/tools/system.php passport-delivery install
php local/modules/kk.korsac/tools/system.php passport-delivery check
php local/modules/kk.korsac/tests/Integration/system_passport_http_smoke.php
php local/modules/kk.korsac/tests/Integration/system_passport_http_smoke.php --public-id=<ACTIVE_PUBLIC_ID>
```

Install is idempotent and repairs missing/outdated owned files and duplicate or
malformed owned rewrite entries. It rejects foreign-file/route conflicts instead
of overwriting applications. Check/smoke are read-only, and smoke is not a real
HTTP route test. Uninstall removes only owned executable files/registration;
Passport business data and unrelated application files/routes stay intact.
Valid slashless paths redirect to a relative canonical path with no query.
Malformed paths outside the narrow rewrite use the site's native 404 handler,
which must also pass the real HTTP privacy/status gate.

No absolute origin setting or QR image is added; future absolute QR payload must
use an explicitly validated HTTPS origin plus the public path, never HTTP_HOST
or a secret. Full contracts, ownership proof, curl commands and remaining live
gate: [KORSAC-PUBLIC-PASSPORT-DELIVERY.md](../../../docs/architecture/KORSAC-PUBLIC-PASSPORT-DELIVERY.md).
Human architecture review is required before live HTTP testing/merge. Real
standalone Bitrix routing has not been validated by pure tests; no auto-merge.


## System Ownership / Claim Core v1

v0.13 / migration `2026_10_14_009_system_ownership_core` makes System.USER_ID the
sole current-owner association and adds append-only ownership history. Normal
creation stays unowned; ordinary ORM cannot assign/rewrite USER_ID. An explicit
OwnershipService claim requires an ACTIVE System/Passport, the correct existing
credential and an existing ACTIVE standalone KORSAC User. Parent → Passport
locking, conditional first assignment and audit append occur in one transaction.
Wrong secret is always a generic rejection before owner-conflict disclosure.
Same-user repeat with a valid credential changes nothing; another user cannot
reassign the System. Order customer never implies ownership.

Migration 009 checks legacy non-null USER_ID before any ownership DDL and blocks
with a count-only operator diagnostic; it does not clear owners or fabricate
history. Once applied, ordinary repeat migrate permits legitimate v0.13 owners.
The System/Passport SQL schema, prior migrations, credentials/activation and
public DTO/HTTP page remain unchanged. Public Passport reveals no owner/claim data.

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tools/system.php ownership-show --system=<KS-ID>
php local/modules/kk.korsac/tools/system.php ownership-history --system=<KS-ID>
php local/modules/kk.korsac/tests/Integration/system_ownership_smoke.php
# Architecture-reviewed fresh dedicated test System without Passport; persists business history.
php local/modules/kk.korsac/tests/Integration/system_ownership_smoke.php --system=<FRESH_TEST_SYSTEM> --user=<ACTIVE_TEST_USER_ID> --confirm-write
```

CLI exposes only safe operator reads, never a claim command with secret argv.
Optional `--concurrency=4` uses independent real workers with secret input only
through anonymous stdin pipes, never argv/env/stdout/logs/files. Live writes retain
Passport handoff and owner/audit; there is no cleanup/reset/reissue operation.
Future HTTP must derive userId from the authenticated server-side session; no
public claim endpoint/form or My systems UI is implemented here. Transfer,
release/revocation, account lifecycle handling and secret reissue remain separate
future operations. Normal uninstall preserves owner/audit/Passport history.

Full security ordering, consistency, legacy policy and required live commands:
[KORSAC-SYSTEM-OWNERSHIP.md](../../../docs/architecture/KORSAC-SYSTEM-OWNERSHIP.md).
Human architecture review is required before controlled writes; pure tests do
not validate live Bitrix/MySQL locking. No auto-merge.


## Actual System Components Core v1

v0.14 / `2026_10_16_010_system_component_lifecycle_core` keeps the existing
component table and schema. The explicit service installs into empty slots and
replaces an expected current component atomically, preserving removed hardware.
Exact install/replacement retries return the existing result without timestamp
writes. Parent System FOR UPDATE, coherent slot history and closed ordinary ORM
add/update/delete/repository-create paths enforce one active component per slot.
Serials are nullable private data; no serial UNIQUE index or serial CLI argument
is introduced. Public labels/names must contain only public hardware information.

Migration 010 read-only validates/adopts compatible existing rows; invalid
legacy data blocks without repair. `schema.php check` adds a distinct components
data-health section. Ownership, production, Sale, Passport credentials/activation
and handoff/warranty times remain independent and unchanged.

```bash
php local/modules/kk.korsac/tools/system.php component-current --system=<KS-ID>
php local/modules/kk.korsac/tools/system.php component-history --system=<KS-ID>
php local/modules/kk.korsac/tests/Integration/system_component_smoke.php
```

These reads expose safe component IDs/slots/names/times, never serials. After human
architecture review, the standalone gate is:

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tests/Unit/run.php
php local/modules/kk.korsac/tests/Integration/system_component_smoke.php
php local/modules/kk.korsac/tests/Integration/system_component_smoke.php --system=<TEST_SYSTEM> --confirm-write
php local/modules/kk.korsac/tests/Integration/system_component_smoke.php --system=<ANOTHER_TEST_SYSTEM> --confirm-write --concurrency=4
```

Write mode requires an ACTIVE dedicated test System with no history in its
selected slot (default SSD_1, optional --slot=<STABLE_CODE>). It persists the
successful install/replacement history and checks active Passport projection
when available; no Passport/owner is required or created. Concurrent workers use
NULL serials and test payloads over stdin, with exactly one write per phase.
Use a new empty slot/System to rerun; do not delete successful physical history.
Check real `/passport/{PUBLIC_ID}/` HTTP separately after replacement, including
absence of old hardware/private data. No live Bitrix/MySQL concurrency or deployed
HTTP gate has been certified in this cloud. See the architecture document for
all invariants, fixture rules, safe errors and exact gate details. No auto-merge.


## My Systems Core v1

v0.15 (2026-10-16) adds a read-only MySystemsService and strict account DTO.
Membership is exactly current System.USER_ID; neither Order customer nor former
ownership history grants access. CANCELLED-but-currently-associated Systems stay
listed. No User activity/authentication decision is duplicated by the core.

The projection contains human systemId, systemType, immutable modelName,
lifecycleState, nullable persisted transfer/warranty-start dates, an ACTIVE-only
canonical Passport path and current component typeCode/slotLabel/name. No internal
IDs, user/order/product/snapshot data, serial/slotKey, removed history, secret/hash
or invented warranty end is returned. PREPARED Passport has no path or dates.

One SELECT pages parent Systems by CREATED_AT DESC/SYSTEM_ID DESC using the
existing USER_ID index, with default limit=50, max=100 and offset>=0. It joins only
that bounded window's Passport, latest ownership state and actual component
history for existing consistency validators. Contradiction fails the entire page;
operational errors are sanitized. No mutation, locking query, schema/migration,
new account table or uninstall change is introduced.

```bash
php local/modules/kk.korsac/tools/system.php my-systems --user=<BITRIX_USER_ID>
php local/modules/kk.korsac/tools/system.php my-systems --user=<BITRIX_USER_ID> --limit=50 --offset=0
php local/modules/kk.korsac/tests/Integration/my_systems_smoke.php
php local/modules/kk.korsac/tests/Integration/my_systems_smoke.php --user=<BITRIX_USER_ID>
```

Smoke is read-only with no write mode. Use confirmed owner and empty-account
fixtures for the standalone live gate; do not modify ownership to prepare reads.
No live Bitrix/MySQL read gate is certified in this cloud.

**Customer account HTTP delivery is not implemented.** The frozen kk-template
Account Experience remains the UX reference. Future HTTP must derive userId from
the authenticated Bitrix server session/CurrentUser and never accept userId,
ownerUserId or actorUserId from client GET/POST/JSON/hidden fields. CLI --user is
trusted operator input only. Ownership request, unlink/transfer, auth/profile/UI
and warranty duration/end remain separate work. Full query/privacy/error/live-gate
contract: [KORSAC-MY-SYSTEMS.md](../../../docs/architecture/KORSAC-MY-SYSTEMS.md).
Human architecture review required before merge; do not auto-merge.
