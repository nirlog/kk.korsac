# KORSAC System core v0.9

## Definition and installation boundary

A **System** is one concrete physical KORSAC computer, created explicitly from
one physical ordered PC unit. It is independent from the Catalog Product,
ProductConfiguration, configuration snapshot, Basket line, Order and Account.
It is not a public Passport.

```text
Catalog Product → configured Basket line → immutable ConfigurationSnapshot
               → standalone KORSAC Order → one physical PC unit → System
```

All tables, source Order/User/Catalog identities and snapshots belong to the
standalone KORSAC Bitrix installation and its database. “KORSAC by King-Komp”
is branding, not a runtime integration contract. No King-Komp site/database,
Users, Sale Orders, Catalog or HL blocks are consulted. Neither `kk-template`
nor `kk.price-update` is changed.

## Human identity and allocation

`KS-2610-0012` means KORSAC System, creation period October 2026, sequence 12.
The immutable `SYSTEM_ID` is generated on the server; the caller cannot supply
it. Each `YYMM` period has a persistent counter in
`b_kk_korsac_system_sequence`:

| Column | SQL contract |
| --- | --- |
| PERIOD | CHAR(4), primary key |
| LAST_VALUE | INT UNSIGNED, last consumed number |
| UPDATED_AT | DATETIME |

`BitrixSystemSequenceAllocator` uses a **separate nonpersistent D7 mysqli
connection** to the same installation's configured default database. In its own
transaction it creates the period row if absent with an upsert that does not
reset an existing value, locks it with `SELECT ... FOR UPDATE`, checks the
ceiling, increments it and commits. InnoDB serializes parallel allocations,
including first allocation for a new month. No `COUNT`, `MAX`, memory or Bitrix
Option counter is involved. The default D7 driver must be
`Bitrix\Main\DB\MysqliConnection`; this supports MySQL/MariaDB. No separate DB
credential is introduced. A driver/access/locking failure is surfaced safely.

A sequence is in `1..9999`. Allocation beyond 9999 fails with
`system_sequence_exhausted` without changing the value or expanding the format.
A failed System insert or rollback can leave a gap. Numbers are never decremented
or reused; cancellation will not release one. The allocator must run against the
writable primary database, never a replica. Do not reset sequence state during
normal operation or uninstall.

`SystemIdGenerator` separates date-based formatting from SQL allocation. The
normal service clock is the PHP/Bitrix server application timezone, never browser
time; the creation instant also supplies persisted timestamps. Configure the
exact production business timezone consistently across PHP/Bitrix and all
standalone KORSAC application workers. The `YYMM` format has a century horizon;
a future format migration must precede any century collision rather than
silently reusing a period.

## System persistence

`b_kk_korsac_system` uses an auto-increment unsigned integer `ID` as the internal
primary key. `SYSTEM_ID` is the separate unique 12-character human identity.

| Columns | Meaning |
| --- | --- |
| SITE_ID | Required installation site, at most 10 UTF-8 characters |
| SYSTEM_TYPE | MODEL or CUSTOM |
| MODEL_NAME | Required historical display snapshot, at most 255 UTF-8 characters |
| IBLOCK_ID, PRODUCT_ID | Nullable coherent pair of positive catalog identities |
| ORDER_ID, ORDER_BASKET_ID, ORDER_UNIT_INDEX | Required positive source identities; unit index is 1-based |
| CONFIG_SNAPSHOT_KEY | Nullable existing 64-character lowercase hexadecimal snapshot key |
| USER_ID | Nullable; always NULL on normal creation |
| LIFECYCLE_STATE | ACTIVE on creation; CANCELLED reserved for a future lifecycle service |
| CREATED_AT, UPDATED_AT | Server application timestamps |

A `MODEL` is based on a catalog computer model; a `CUSTOM` is an individual build.
Neither requires permanent live Catalog resolution for its historical name.
Product references may be omitted as a pair, including for custom builds.

Indexes are UNIQUE `SYSTEM_ID`, UNIQUE
`(ORDER_ID, ORDER_BASKET_ID, ORDER_UNIT_INDEX)` and nonunique indexes on USER_ID,
ORDER_ID, PRODUCT_ID, CONFIG_SNAPSHOT_KEY and LIFECYCLE_STATE. All three source
columns are NOT NULL, so the uniqueness boundary covers every core-created
System. There are no foreign keys into Bitrix User, Sale, Catalog or other modules;
relationships and deletion policy remain application-owned.

For a PC line of quantity two, explicit calls use unit indexes 1 and 2. An
ordinary monitor must not produce a System. This core trusts an internal caller
that has already identified an eligible physical PC unit; it does not load Orders
or infer eligibility from names such as `system`, `CONFIG` or `computer`. A later
Sale integration must use authoritative KORSAC Basket/Order metadata such as
KORSAC_CONFIGURED and preserved snapshot properties.

## Snapshot relation and idempotent creation

The existing immutable snapshot stays in `b_kk_korsac_config_snapshot`.
`CONFIG_SNAPSHOT_KEY` refers to its SNAPSHOT_KEY. No payload, price, option list
or second snapshot model is copied into System. Ordered configuration and actual
installed physical components are distinct concepts.

`SystemCreationService` takes an array with only SITE_ID, ORDER_ID,
ORDER_BASKET_ID, ORDER_UNIT_INDEX, SYSTEM_TYPE, MODEL_NAME and optional IBLOCK_ID,
PRODUCT_ID, CONFIG_SNAPSHOT_KEY. Integer inputs must be actual positive integers
within the unsigned INT range; text is bounded UTF-8 without control characters.
Unknown fields, SYSTEM_ID, USER_ID, lifecycle overrides, PUBLIC_ID, SECRET_CODE
and warranty fields are rejected.

Example inside an initialized standalone KORSAC installation, after the caller
has authoritatively identified a physical PC unit:

```php
$service = new \KK\Korsac\System\SystemCreationService(
    new \KK\Korsac\System\BitrixSystemRepository(),
    new \KK\Korsac\System\SystemIdGenerator(new \KK\Korsac\System\BitrixSystemSequenceAllocator()),
    new \KK\Korsac\Cart\BitrixConfigurationSnapshotRepository(),
);
$system = $service->create([
    'SITE_ID' => 's1', 'ORDER_ID' => 123, 'ORDER_BASKET_ID' => 456,
    'ORDER_UNIT_INDEX' => 1, 'SYSTEM_TYPE' => 'MODEL',
    'MODEL_NAME' => 'KORSAC PLAY 1440', 'IBLOCK_ID' => 2, 'PRODUCT_ID' => 367,
    // Optional CONFIG_SNAPSHOT_KEY must come from the immutable ordered snapshot.
]);
```

1. Validate the request, including a coherent optional product pair and exact key format.
2. Find the existing System by the three-column order-unit identity; return it unchanged if present.
3. If a snapshot is supplied, read it through the existing snapshot repository,
   verify existence, matching SITE_ID and matching IBLOCK_ID/PRODUCT_ID when supplied.
4. Allocate an immutable identifier and insert with USER_ID=NULL and ACTIVE.
5. If insertion fails, reload the authoritative order unit and return the winner
   if one exists; otherwise return a safe persistence failure.

Race recovery uses a D7 SQL `SELECT ... FOR UPDATE` current read so a committed
winner is visible even inside a caller-managed REPEATABLE READ transaction.
Ordinary repository reads use D7 ORM; this narrowly scoped locking read is needed
for concurrency recovery. Creation does not commit or roll back a caller's
transaction. A genuine deadlock/access/persistence failure remains a safe domain
failure; caller-managed transactions must be rolled back and the whole request
retried as appropriate. The independently allocated number remains consumed.

Repeated calls return the same identity without updating its name, snapshot,
owner or lifecycle and without consuming another number. Supplied snapshot
relations are checked only for new creation; existing history is never rebuilt
from current Product properties. Persistence has a narrow repository boundary:
create, findById, findBySystemId and findByOrderUnit. There is no normal identifier
update operation; ordinary SystemTable ORM updates also reject identifier/source
changes.

Safe machine errors include `system_source_invalid`, `system_type_invalid`,
`system_snapshot_not_found`, `system_snapshot_mismatch`,
`system_sequence_exhausted`, `system_sequence_failed` and
`system_persist_failed`. Diagnostics contain codes rather than SQL, credentials,
stack traces or filesystem paths. These are internal services with no public
controller yet.

## Actual components and history

`b_kk_korsac_system_component` records actual physical installations:

| Columns | Meaning |
| --- | --- |
| ID | Internal unsigned auto-increment primary key |
| SYSTEM_ID | Internal numeric System.ID, not the human KS identifier |
| TYPE_CODE | Component category, bounded uppercase code (e.g. GPU, SSD, RAM) |
| SLOT_KEY | Stable physical position (GPU, SSD_1, SSD_2, RAM_1, etc.) |
| SLOT_LABEL | Historical display label such as «Видеокарта» or «SSD 2» |
| NAME | Actual installed component display snapshot |
| SERIAL_NUMBER | Nullable internal physical/service serial number |
| INSTALLED_AT | Installation timestamp |
| REMOVED_AT | NULL for active installation, non-NULL for removed history |
| CREATED_AT, UPDATED_AT | Record timestamps |

Indexes cover SYSTEM_ID, (SYSTEM_ID,SLOT_KEY) and (SYSTEM_ID,REMOVED_AT). Multiple
SSD/RAM/etc. positions are supported. A slot index is deliberately nonunique to
retain replacement history. The future replacement service must close the old
row and insert a new one, never overwrite the old component name. Enforcing one
active component per slot belongs to that future transactional service.

`BitrixSystemComponentRepository` checks the parent System, inserts a physical
record and reads either all history or only REMOVED_AT IS NULL. It provides no
repair/replacement/delete operation. Same-module relationships are enforced by
the application without physical foreign keys.

Serial numbers are private internal/service data. Future public Passport DTOs
must never include SERIAL_NUMBER. No public DTO/API is added here.

## Ownership and lifecycle boundaries

System identity does not depend on User. Normal creation leaves USER_ID NULL;
initial user assignment happens later at physical handoff. Future ownership
changes must not alter SYSTEM_ID.

ACTIVE is a technical lifecycle value; CANCELLED is reserved. Neither encodes
production progress. «Передан кладовщику», «Не хватает комплектующих», «Отправлен
на сборку» and «Готов к выдаче» belong to later production-status work.

## Installation, upgrade, health and uninstall

Module version is 0.9.0, dated 2026-10-12. The new migration is
`2026_10_12_006_system_core`. Fresh `DoInstall()` creates the existing HL schema,
existing snapshots and all three System tables. On an up-to-date 0.8 installation,
only migration 006 is newly recorded. Previous migration IDs are unchanged.

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
```

SystemSchemaDefinition centrally drives SQL DDL, ORM fields and health checks.
The migration inspects all existing System tables before writing. Incompatible
columns, nullable constraints, engine, primary key or existing indexes fail
without destructive correction. Compatible missing secondary indexes are added;
missing tables are created. DDL is idempotent and completed work is retained for
a safe retry after failure. MySQL DDL implicitly commits; never assume schema
migration can be rolled back. The account requires CREATE/INDEX permissions and
SELECT/INSERT/UPDATE for sequence allocation and repository writes.

`schema.php check` retains the existing HL diagnostics and adds structured System
diagnostics for missing/incompatible tables, fields, InnoDB engine, full indexes
and uniqueness. MariaDB integer display widths are normalized; prefix indexes
cannot satisfy the required full unique constraints. The new tables use utf8mb4.
The check does not repair records or validate live Order/User existence.

Ordinary uninstall preserves Systems, components, sequence state, snapshots,
HL data and migration history. Physical history is business data.

## Validation

```bash
php local/modules/kk.korsac/tests/Unit/run.php
node local/modules/kk.korsac/tests/Frontend/run.js
php local/modules/kk.korsac/tests/Integration/system_core_smoke.php
```

The last command is read-only by default and checks schema in an initialized
Bitrix installation. Without Bitrix it exits 2 with `bitrix_environment_required`.
No migration or write is performed by default.

For a dedicated initialized test database, explicitly permit synthetic writes:

```bash
php local/modules/kk.korsac/tests/Integration/system_core_smoke.php --confirm-write
php local/modules/kk.korsac/tests/Integration/system_core_smoke.php --confirm-write --concurrency=4
```

Write smoke creates one synthetic custom System (no real Order), verifies reads,
idempotency, owner/lifecycle and two component positions with active/history
filtering. It rolls back System/component fixtures and verifies their absence.
Sequence allocation uses its separate connection and **remains committed**: the
first command consumes one monthly number; concurrency=N consumes N+1. This is
intentional and checks that a rollback cannot reclaim identifiers. Never run it
to keep a production counter gapless. No customer rows are modified. Optional
concurrency launches 2..8 independent PHP workers against the same current period
and verifies unique allocations; proc_open and the same CLI extensions/config are
required. It does not claim to validate concurrent order-unit creation; that path
is covered by a deterministic race fake and DB-level unique-schema checks.

Pure tests cover ID formatting/limits/month/timezone, source/domain validation,
idempotency/race recovery/gaps, snapshot compatibility, forbidden overrides,
component history, safe persistence failures, fresh/idempotent migration,
upgrade history and incompatible schema/index detection. Live Bitrix/MySQL smoke
must still be run in the deployment test installation; pure tests alone do not
prove database locking or deployed ORM compatibility.

## Current scope and next stages

Implemented: persistent System identity, safe monthly allocator, explicit creation,
existing snapshot relation, actual component persistence, D7 mappings, migration
and schema health support. No HL block is added or changed by this PR.

Planned separately: authoritative Sale event automation, production statuses and
history, physical handoff/user assignment, ownership lifecycle, public Passport
and privacy-safe DTOs, PUBLIC_ID/SECRET_CODE/QR, transfer and warranty, service
repair/upgrade/data correction, inquiries and Bitrix Admin UI. Payment, delivery,
inventory, suppliers and compatibility engines remain outside this core. No
frontend change or external site integration is introduced.
