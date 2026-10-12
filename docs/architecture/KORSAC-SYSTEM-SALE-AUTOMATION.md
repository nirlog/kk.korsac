# Sale → System and production status v0.10

## Domain boundary

```text
Standalone KORSAC Sale Order
  → kk.korsac Basket line + KORSAC_CONFIGURED=Y
  → existing immutable ConfigurationSnapshot
  → quantity physical units (indexes 1..N)
  → System
  → production current state + immutable history
```

This is the first operational bridge after System core. All Orders, Users,
Catalog references, snapshots and Systems belong to the standalone KORSAC
Bitrix installation/database. King-Komp is not a runtime dependency.
`kk-template` and `kk.price-update` are unchanged.

The immutable human SYSTEM_ID remains `KS-YYMM-NNNN`. Child rows always use
SYSTEM_INTERNAL_ID for numeric System.ID. D7 identities and revisions are
signed INT; positive identities are bounded by 2147483647. Production progress
never changes System.LIFECYCLE_STATE, owner or frozen creation-source fields.

## Production registry and labels

| Permanent code | Default Russian label |
| --- | --- |
| WAREHOUSE | Передан кладовщику |
| PROCUREMENT | Передан в отдел снабжения |
| MISSING_COMPONENTS | Не хватает комплектующих |
| ASSEMBLY | Отправлен на сборку |
| READY_FOR_DELIVERY | Готов к выдаче |

`ProductionStatusRegistry` owns codes. `ProductionStatusLabels` reads bounded
UTF-8 label overrides from Bitrix Option `kk.korsac/system_production_label_<CODE>`.
Changing a label never changes a code. No production transition graph is imposed
in v1; any valid status may change to any other. This is internal business state,
not a public Passport/status DTO.

## Persistence, transaction and history

Migration `2026_10_12_007_system_sale_automation` introduces:

| Current table: b_kk_korsac_system_production_status | SQL contract |
| --- | --- |
| SYSTEM_INTERNAL_ID | INT primary key; parent System.ID |
| STATUS_CODE | VARCHAR(32), NOT NULL |
| REVISION | INT, NOT NULL |
| UPDATED_AT | DATETIME, NOT NULL |

| History: b_kk_korsac_system_production_status_history | SQL contract |
| --- | --- |
| ID | Signed INT auto-increment primary key |
| SYSTEM_INTERNAL_ID, REVISION | Signed INT, NOT NULL |
| FROM_STATUS_CODE | Nullable VARCHAR(32) |
| TO_STATUS_CODE, SOURCE_CODE | VARCHAR(32), NOT NULL |
| ACTOR_USER_ID | Nullable positive signed INT32 |
| CREATED_AT | DATETIME, NOT NULL |

History indexes are UNIQUE (SYSTEM_INTERNAL_ID,REVISION), plus SYSTEM_INTERNAL_ID,
TO_STATUS_CODE and CREATED_AT. There are no physical foreign keys. The new
ProductionStatusSchemaDefinition independently drives DDL, ORM mappings and
health checks; migration 006 and its SystemSchemaDefinition remain frozen.

`ProductionStatusService::setStatus(int $systemInternalId, string $statusCode,
string $sourceCode, ?int $actorUserId = null)` validates identities, permanent
status and source codes (`SALE_AUTOMATION` or `MANUAL`). It starts a D7 transaction
on the installation's default writable connection. The gateway first locks the
**existing parent System row** with SELECT FOR UPDATE, then reads the current
status row FOR UPDATE. The parent is a stable synchronization anchor even if no
current status row exists, preventing the first-row race without invalid
placeholder status rows or memory locks. A missing parent fails `system_not_found`.

An unchanged status returns the current state without timestamp/revision/history
writes. A real change writes current state and appends history within the same
transaction. First revision is 1 and FROM_STATUS_CODE=NULL. Each change increments
revision by one; exhaustion at signed INT32 maximum fails explicitly. Both writes
commit together or roll back together. No business service updates/deletes
history; ordinary history ORM update/delete also fail explicitly. Concurrent
transitions serialize on the same parent lock. The server application timezone
contract established for System core also applies to status timestamps.

`ensureInitial()` is separate from `setStatus()`: it inserts missing WAREHOUSE
with source SALE_AUTOMATION, and preserves any existing state. Retry never resets
PROCUREMENT/ASSEMBLY/READY_FOR_DELIVERY back to WAREHOUSE.

## Automation settings and persistent event

Automation is disabled by default. Backend settings are:

- `system_sale_automation_enabled` (`N`/`Y`, default N).
- `system_sale_trigger_status_id` (exact authoritative Sale ORDER STATUS_ID).

`SaleAutomationConfiguration::configure()` validates that the exact ID exists
in Sale StatusTable with TYPE=O, temporarily disables execution while writing the
trigger, then enables automation. No translated-name lookup and no hard-coded
status ID for «Согласован» exist. Disable retains the trigger for manual fallback.
Labels do not participate in trigger decisions.

A persistent D7 handler is registered for `sale/OnSaleOrderSaved`, targeting
`KK\Korsac\System\SaleOrderSavedHandler::onOrderSaved`. Fresh install and
`schema.php migrate` register it idempotently; migrate repairs registration even
when 007 is already applied. EventManager's persistent UNIQUE_ID protects repeat
registration; the registration service removes duplicate/malformed canonical
registrations before recreating the expected v2 handler. Health reads persistent
metadata and requires exactly one expected handler. Normal uninstall unregisters
this executable hook and preserves Systems, production current/history,
components, counters, snapshots, HL data and migration history.

The event entry delegates to `SaleOrderAutomation`, which reads current settings,
returns immediately when disabled or the Order status differs, projects the
post-save Order and calls the same `OrderSystemSynchronizationService` as CLI.
It never modifies or re-saves the Order. Failures are caught and logged through
Bitrix EventLog with only order ID and safe machine code; logger failure is also
contained. SQL, credentials, paths, snapshot payloads and customer details are
never placed in diagnostics. Synchronization failure does not become a KORSAC
application-level failure for the already saved Order. Unrelated Sale/caller
transaction errors retain their own normal platform behavior.

## Projection and complete preflight

`BitrixOrderProjectionAdapter` consumes the post-save entity or an Order loaded
by CLI. Its neutral DTO contains order ID/site/status and Basket item ID, MODULE,
PRODUCT_ID, persisted NAME, quantity and property values grouped by CODE. Duplicate
values are retained so validation can reject ambiguity. It does not query live
Catalog names/configuration or customer/account/address fields.

Eligibility requires MODULE=`kk.korsac` and KORSAC_CONFIGURED=`Y`. Ordinary
monitors/accessories and KORSAC lines without that marker are ignored. Duplicate
Y markers are rejected. Product names, sections, TYPE_PC, CSS/static `system`
values and translated labels do not establish eligibility.

Each eligible line must contain exactly one value for KORSAC_SNAPSHOT_KEY,
KORSAC_SNAPSHOT_HASH and KORSAC_SNAPSHOT_VERSION. Key/hash must be 64-character
lowercase hex. The existing repository must return the matching key and hash,
current stored schema version and Basket version must equal
ConfigurationSnapshot::SCHEMA_VERSION, and snapshot site/product must match
Order SITE_ID and Basket PRODUCT_ID. Snapshot.IBLOCK_ID supplies the catalog
source. Snapshot payload is neither copied nor reconstructed. An additive
schemaVersion field on the existing snapshot DTO exposes the actual persisted
version for validation; existing snapshot creation semantics remain unchanged.

New Systems use SYSTEM_TYPE=MODEL and the persisted historical Basket NAME.
CUSTOM remains an explicit path; it is never guessed by this synchronizer.

Quantity must be numeric, finite, positive and integral. The named
`MAX_UNITS_PER_LINE=1000` bounds physical PC allocation per Basket line.
Quantity 3 maps to ORDER_UNIT_INDEX 1,2,3. Integers, integral floats and numeric
strings representing integral quantities are accepted; fractional/zero/negative,
non-finite and oversized values fail before allocation.

All eligible lines are validated before the first missing unit is created.
Existing Systems for the Order are preflighted across all lines. Any unit index
outside current quantity, removed/ineligible Basket line, or changed immutable
snapshot/site/catalog source fails `system_order_unit_conflict`. No System is
deleted, cancelled, renumbered or reassigned. Historical model names already
stored on existing Systems remain unchanged. A future audited Data Correction
or cancellation operation must handle exceptional Order edits.

Only after complete preflight does the synchronizer use SystemCreationService
for each unit and ensure missing initial production state. The additive
`createWithResult()` reports whether an actual insert occurred; the original
`create()` still returns exactly the same record and preserves its idempotency.
An insert race winner counts as existing, so result counts do not guess from a
stale preflight. Result includes order ID, eligible line count, physical unit
count, existing/created System counts and human System IDs.

Quantity increase 1→3 creates units 2 and 3 only. Repeated sync creates zero
Systems, allocates zero numbers and appends zero initial-status history. If
System creation succeeds but status initialization fails, retry finds the same
System and repairs missing status without another number. Persistence failure
can leave partial progress; retry/manual sync converges through idempotency.
Concurrent Order edits are not serialized with the Sale Order itself; a later
sync detects immutable-source/orphan conflicts rather than destructively
reconciling them. The core does not modify the Order to hide any conflict.

## Operations CLI

Run inside the initialized standalone KORSAC document root:

```bash
php local/modules/kk.korsac/tools/system.php sale-config show
php local/modules/kk.korsac/tools/system.php sale-config set --status=<ORDER_STATUS_ID>
php local/modules/kk.korsac/tools/system.php sale-config disable
php local/modules/kk.korsac/tools/system.php inspect-order --order=<ORDER_ID>
php local/modules/kk.korsac/tools/system.php sync-order --order=<ORDER_ID>
php local/modules/kk.korsac/tools/system.php status-set --system=<KS-YYMM-NNNN> --status=ASSEMBLY
php local/modules/kk.korsac/tools/system.php status-show --system=<KS-YYMM-NNNN>
php local/modules/kk.korsac/tools/system.php status-labels show
php local/modules/kk.korsac/tools/system.php status-label set --status=WAREHOUSE --label="На складе"
```

Manual sync uses the exact same synchronizer, always requires the configured
trigger status and has no force mode. It remains available while automatic
execution is disabled but a trigger was previously configured. `status-set` uses
the same status service with MANUAL source and NULL actor for CLI. Internal
callers may provide an authorized positive actor ID.

`inspect-order` performs read-only preflight and outputs only safe IDs, status,
quantity and line/unit counts; no customer PII, Basket name, snapshot payload,
hash/key or component S/N is emitted. Sync returns safe IDs/counts. Operational
errors are JSON safe codes with nonzero exit status. CLI never saves an Order.

## Migration and health

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
```

On 0.9 upgrade only new migration 007 is added. Fresh install receives prior
schemas and 007. No historical migration ID or 006 schema semantics change.
Existing incompatible production tables/fields/engine/indexes fail before new
DDL; compatible missing secondary indexes may be added. MySQL DDL implicitly
commits, so partially completed safe DDL is retained for an idempotent retry.
No normal setup/uninstall command drops business history.

Health retains existing HL/System checks and adds read-only snapshot-schema,
production-schema and persistent Sale-handler checks. Snapshot health checks
migration 004's existing types/full indexes without altering its DDL. Missing
or incompatible tables, nullable/unsigned integer mismatches, prefix indexes and
wrong uniqueness are reported through structured safe diagnostics. Database
access requires CREATE/INDEX for migration and SELECT/INSERT/UPDATE for workflow;
Bitrix persistent hook registration also requires its usual metadata rights.

## Validation and live gate

Pure tests exercise status transitions/idempotency/validation, first-row race
protocol, current+history rollback, label/settings invariants, complete Order
preflight, quantity expansion/conflicts, metadata/snapshot/version validation,
status retry recovery, event containment, registration lifecycle, migration 007
and health. The existing PHP/frontend suites also remain required. Fakes verify
protocols and domain behavior; they do not prove deployed D7 locking or real
Sale event dispatch.

The cloud checkout has no initialized Bitrix/Sale test database. **Live Sale
integration has not been executed here.** Before merge, on the dedicated test
installation apply migration/check and run read-only smoke:

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tests/Integration/system_sale_automation_smoke.php
php local/modules/kk.korsac/tools/system.php inspect-order --order=<TEST_ORDER_ID>
```

Configure the real ORDER trigger ID, then use an actual dedicated test Order in
that status with a configured KORSAC PC line and real immutable snapshot:

```bash
php local/modules/kk.korsac/tools/system.php sale-config set --status=<REAL_ORDER_STATUS_ID>
php local/modules/kk.korsac/tests/Integration/system_sale_automation_smoke.php --order=<TEST_ORDER_ID> --confirm-write
php local/modules/kk.korsac/tools/system.php status-set --system=<TEST_SYSTEM_ID> --status=PROCUREMENT
php local/modules/kk.korsac/tools/system.php sync-order --order=<TEST_ORDER_ID>
```

Default smoke is read-only: schema, handler registration, settings and optional
safe inspection. Explicit write smoke persists Systems/current/history against
the supplied test Order; it does not create/edit/re-save that Order and does not
roll back those business writes or reclaim sequence numbers. It verifies units,
initial WAREHOUSE history, repeat counts/IDs, zero repeated allocator calls and
unchanged current/history on repeat. Existing advanced statuses are preserved.
Use a dedicated test Order, not production/customer rows.

Also exercise actual Sale post-save event dispatch on that test Order: disabled
and wrong-trigger saves produce no Systems; the configured trigger creates units
once; a repeated save creates none; increased quantity adds only missing units;
decrease/removal/ineligibility yields a safe logged conflict without blocking the
Order save or deleting history. Verify real concurrent first/change transitions
produce consecutive unique history revisions. Pure fake race tests and read-only
smoke do not complete these live checks. Record actual commands and outcomes for
human architecture review; do not auto-merge.

## Current scope

Implemented: Sale projection, explicit configured-PC eligibility, shared manual/
automatic synchronization, backend settings/labels, persistent post-save hook,
production current/history transactions, migration, health and operational CLI.
Passport, public controllers/DTOs, PUBLIC_ID/SECRET_CODE/QR, warranty/TRANSFER_DATE,
ownership/My systems/requests, repair/service/upgrade/component replacement,
System Admin UI, payment/delivery, inventory/procurement integration, customer
notifications, frontend and King-Komp integration remain outside this PR.
