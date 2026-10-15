# KORSAC Actual System Components Core v1 (0.14.0)

Actual components answer **what physical hardware is installed now**. The immutable
ConfigurationSnapshot answers **what was ordered**. No component is generated
from a snapshot, Product property, HL option, catalog or Basket. Operators record
actual hardware explicitly after a physical System exists.

## Storage and stable slots

`b_kk_korsac_system_component` remains the sole physical installation timeline.
There is no second current-component table and no component copy in Passport.
`SystemSchemaDefinition` remains authoritative for its existing columns/indexes.
`SYSTEM_INTERNAL_ID` references numeric System.ID; human SYSTEM_ID retains its
KS-YYMM-NNNN meaning. All integer identities use positive signed INT32
1..2147483647, the Bitrix D7 IntegerField boundary; only an unsaved internal
component record may have ID=0.

SLOT_KEY is a stable logical physical position within one System. CPU_1, GPU_1,
RAM_1/RAM_2, SSD_1/SSD_2, MB_1, PSU_1, CASE_1 and COOLING_1 are examples, not a
closed registry. TYPE_CODE is an uppercase stable code of at most 32 bytes;
SLOT_KEY uses the same `[A-Z][A-Z0-9_]*` format, at most 64 bytes.

Across a slot's entire history, TYPE_CODE, SLOT_KEY and SLOT_LABEL keep their
meaning. Replacement changes physical NAME/SERIAL_NUMBER only. Historical
intervals cannot overlap; at most one row has REMOVED_AT=NULL, and that row must
be the latest row. Removed-only valid legacy history can be adopted; installing
in its now-empty slot must retain type/label and cannot overlap its last interval.
No standalone remove, reseat, reinstall or data-correction API is provided.

## Public text and private serials

SLOT_LABEL and NAME are **public data**: they can appear at
`/passport/{PUBLIC_ID}/`. Never put serial numbers, customer information, internal
notes, supplier information, purchase cost, service comments or credentials in
these fields. Both require valid UTF-8, 1..255 characters, no controls/format
controls and no leading/trailing whitespace, including Unicode whitespace.

SERIAL_NUMBER is nullable private physical data. A non-null serial has the same
text constraints; its case and bytes are preserved, without trimming or other
normalization. Comparisons are exact and case-sensitive in application code,
independent of MySQL's text collation. Within a System, two different active slots
cannot share the same non-null serial. Removed history does not reserve serials.
There is no global or System-level UNIQUE serial index: manufacturer namespaces
do not justify that database assumption.

The internal record/spec retain serial for lifecycle decisions. Service results
and ordinary operator views contain no serial; neither do public DTO/HTML,
diagnostics, logs, URLs, queries or smoke output. No logging is added. PHP 8.2
SensitiveParameter redacts private spec/serial arguments from native traces.
Do not serialize internal SystemComponent/ComponentSpec as an API response.

## Explicit mutation API

`SystemServiceFactory::components()` wires `SystemComponentService` and the
controlled BitrixComponentGateway. The service accepts:

```php
install(int $systemInternalId, ComponentSpec $component): ComponentInstallResult
replace(int $systemInternalId, int $expectedComponentId, ComponentSpec $replacement): ComponentReplaceResult
```

ComponentSpec contains only typeCode, slotKey, slotLabel, name and nullable
serialNumber. Callers cannot choose component IDs, timestamps, REMOVED_AT,
System lifecycle or Passport fields. Readonly results contain a serial-free
ComponentCurrentView and created/replaced flag, rather than the private record.
The internal application boundary must authorize future operator/admin callers;
this PR introduces no mutation HTTP endpoint or install/replace CLI.

Every mutation requires an existing ACTIVE System. Owner, Passport, Order,
production status, payment and delivery are not prerequisites. Assembly can
precede Passport preparation. A CANCELLED System remains readable historically,
but install/replace, including retries, reject mutation as system_inactive.

An empty slot accepts install with created=true. If its active component matches
all five spec values exactly, install returns created=false and the existing ID
and timestamps without calling the clock. A different spec returns slot_occupied;
install never implicitly replaces hardware.

Replacement requires the expected active component ID. When it matches, the
service validates unchanged slot/type/label, closes the old row and inserts the
new row atomically. An identical model name is allowed, including two units with
no serials. Two identical non-null old/new serials are rejected as
system_component_invalid: data correction/reseat is outside v1.

When the expected ID is no longer current, a retry returns replaced=false only
if the current row exactly matches the replacement spec, its immediate predecessor
is that expected ID in the same System/slot, predecessor.REMOVED_AT equals
current.INSTALLED_AT, and the whole persisted timeline is valid. It returns the
existing new row without calling the clock or changing history. An older
non-immediate ID, different current component, differing spec or broken link is
replace_conflict. The expected ID prevents a lost commit acknowledgement from
silently replacing the newly installed hardware again.

## Transaction, authority and timestamps

Each install/replace is one transaction, on one pinned D7 connection:

1. Validate primitive IDs/spec before locking.
2. Lock parent System FOR UPDATE and validate lifecycle.
3. Lock/read its component timeline in deterministic slot/install/ID order.
4. Validate slot history and active serial uniqueness under that parent lock.
5. Resolve exact retry/conflict before reading the clock.
6. Conditionally close the expected active row, then insert the fixed new row.
7. Commit before returning a successful result.

Locking the full System timeline permits serial validation across slots. The
parent lock serializes different slots of one System, which is intentional in v1.
There is no in-memory/static mutex or unlocked count-before-insert assumption.
The existing nonunique (SYSTEM_INTERNAL_ID,SLOT_KEY) index supports lookup only.
MySQL UNIQUE with nullable REMOVED_AT would allow multiple NULL active rows and
is not used as an invariant guarantee.

Ordinary SystemComponentTable::add/update/delete throw
system_component_immutable. SystemComponentRepositoryInterface and its Bitrix
implementation are read-only: the old generic create() has been removed. The
only controlled writes are fixed SQL close/insert operations scoped to one
locked parent/slot. Calls outside a transaction, before locking, for a different
System/slot, through read-only scope or after completion are rejected. The gateway
rechecks slot/serial/time invariants and refuses close-only or failed-write commit,
even if a callback swallows the write exception. No generic field-update API is
exposed.

Conditional close updates REMOVED_AT/UPDATED_AT only, with predicates for ID,
SYSTEM_INTERNAL_ID, SLOT_KEY and REMOVED_AT IS NULL; exactly one affected row is
required. Old name, serial, type/slot/label and installation/creation time remain
unchanged. A removed row is permanently closed. Close/insert/pre-completion commit
failures roll back both halves. A commit acknowledgement lost after the server
commits is a network ambiguity: retry resolves against the committed timeline.
Pure failure tests do not claim to reproduce that network condition.

The service clock uses the existing PHP/Bitrix application timezone convention
and normalizes to SQL DATETIME seconds. Initial INSTALLED_AT=CREATED_AT=UPDATED_AT,
REMOVED_AT=NULL. Replacement sets old.REMOVED_AT=old.UPDATED_AT=new.INSTALLED_AT
=new.CREATED_AT=new.UPDATED_AT. Operation time cannot predate System.CREATED_AT,
the current installation or the previous removed-only interval. No backdating
parameter is accepted.

## Reads, errors and Passport

`SystemServiceFactory::componentReads()` provides current()/history() under the
same parent lock, with consistency validation and no writes. Current order is
SLOT_KEY ASC, ID ASC; history is SLOT_KEY ASC, INSTALLED_AT ASC, ID ASC. Replacing
SSD_1 does not move it behind SSD_2 merely because its database ID is newer.
The read-only Passport repository applies the same stable ordering.

Operator CLI commands are read-only and reject non-CLI execution before Bitrix
bootstrap:

```bash
php local/modules/kk.korsac/tools/system.php component-current --system=<KS-ID>
php local/modules/kk.korsac/tools/system.php component-history --system=<KS-ID>
```

Views contain componentId, typeCode, slotKey, slotLabel, name, installedAt,
removedAt and active. They never contain serial. These internal views are separate
from PublicPassportComponent. There is no --serial argument or mutation command.

Controlled errors use system_component_ plus invalid, not_found, system_inactive,
slot_occupied, replace_conflict, serial_conflict, state_invalid, read_failed,
persist_failed or immutable. Repository/infrastructure reads normalize to
read_failed. Invalid persisted history is state_invalid and is never repaired.
Errors contain only their safe code, with no conflicting slot/component/serial,
SQL, driver detail, private context, previous exception, path or customer data.

PublicPassportService keeps its exact seven-field DTO allowlist and three-field
component projection: typeCode, slotLabel, name. It reads active rows only.
After A→B, Passport shows B while A remains privately in history. No component ID,
slotKey, serial, installation/removal time or history is added to DTO/HTML. An
ACTIVE Passport does not freeze actual hardware or grant mutation authority.
Replacement does not alter PUBLIC_ID/secret, Passport state, handoff or warranty
start. Ownership USER_ID/history, Sale/snapshots and production workflow are
unchanged; no implicit production transition is introduced.

## Migration 010 and data health

Version/date are 0.14.0 / 2026-10-16. Migration
`2026_10_16_010_system_component_lifecycle_core` adds no table, column, index or
DDL. Historical migrations 001..009 and SystemSchemaDefinition are unchanged.
006 still creates the existing component table. Both installer and schema CLI
apply 010 after 009; repeat migrate skips already-marked 010 without rerunning its
legacy preflight.

Before marking 010, a read-only scan checks every existing component's parent,
shape/signed identity/second-precision timestamps, slot identity, intervals,
active-last rule and active serial uniqueness. The new operation timestamp
equalities are enforced by controlled writes; valid legacy creation/update times
are preserved rather than retroactively rewritten to those new equalities.
Empty or valid legacy history is adopted unchanged. Invalid legacy state raises
system_component_legacy_state_invalid, leaves 010 unapplied and changes no data;
an operational read failure remains system_component_read_failed. Do not clear
rows, fabricate history or rewrite timestamps to pass. An audited correction is
a separate future operation.

`schema.php check` adds a distinct `components: {ok, errors}` data-health section;
the existing systems section continues structural checks. Health uses the same
domain timeline validator. Its joined, ordered scan validates one System group
at a time, reports the first contradiction category per group and aggregates
counts without IDs/serials. Categories include orphan_parent, malformed,
duplicate_active_slot, slot_identity, temporal_overlap, active_not_latest and
active_serial_conflict. Scan failure is a safe read error, never health success.
Normal uninstall preserves all Systems, active/removed components and serials,
Passport, ownership and migration history; no DROP or business-history delete.

## Validation and required standalone live gate

The pure suite covers installs/exact retries, replacements/immediate-predecessor
retries/stale conflicts, immutable slot meaning, signed/text/serial/time boundaries,
serial privacy and conflicts, deterministic serialized competitors, rollback,
real gateway SQL/authority guards, corrupt history/legacy migration adoption and
rejection, stable reads, actual component A→B Passport DTO/HTML and CLI output.
These tests are not proof of real MySQL concurrency.

**This cloud has no initialized Bitrix/MySQL installation. No live component
write, real database concurrency or standalone HTTP gate has been certified.**
Human architecture review is required before controlled component writes and
concurrency testing. On the standalone test installation, run:

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tests/Unit/run.php
php local/modules/kk.korsac/tests/Integration/system_component_smoke.php
php local/modules/kk.korsac/tests/Integration/system_component_smoke.php --system=<TEST_SYSTEM>
php local/modules/kk.korsac/tests/Integration/system_component_smoke.php \
  --system=<TEST_SYSTEM> \
  --confirm-write
php local/modules/kk.korsac/tests/Integration/system_component_smoke.php \
  --system=<ANOTHER_TEST_SYSTEM> \
  --confirm-write \
  --concurrency=4
```

Default mode is read-only: module availability, System/Passport structure and
component data health. Optional --system prints safe current/history views. Write
mode requires an explicitly selected ACTIVE dedicated test System and an entirely
unused slot (default SSD_1; optional --slot=<STABLE_CODE>). No numeric ID or known
server fixture is hard-coded. Owner/Passport are not needed; an existing ACTIVE
Passport enables the additional public projection assertion without activation
or secret issuance.

Write smoke verifies install/exact retry, occupied rejection, replacement/exact
retry, exactly two linked historical rows/one active row, unchanged System and
Passport, and active Passport's current public projection when available. Single
mode holds synthetic serials only in memory, never output. Concurrency=2..8 uses
real PHP workers for both identical install and identical expected-ID replacement;
exactly one worker reports a write in each phase and the others converge. Workers
use NULL serials and anonymous stdin for the synthetic operation payload; no
private serial goes through argv/env/stdout/logs/files. proc_open and the same
deployed PHP configuration are required. Unit races are not live locking proof.

Successful component write smoke **permanently retains physical history**. It
does not delete records, clear fields or roll back successful replacements for
repeatability. Use another empty slot/System for the next run; failures may leave
legitimate committed partial progress for investigation. The earlier
system_core_smoke retains its distinct rollback-only synthetic System fixture;
its component calls now use the lifecycle service and real MySQL savepoints
within that outer rollback, rather than reopening generic component create().

For an ACTIVE Passport fixture, also check real HTTP after replacement:

```bash
curl --fail --silent --show-error https://<TEST_HOST>/passport/<PUBLIC_ID>/
```

Confirm new public name/slot label, absence of the removed name, serials, internal
slotKey and component IDs, and unchanged route/status/header behavior. Unit HTML
tests and a core projection smoke do not certify deployed Bitrix routing.
Do not auto-merge.

## Excluded scope

No inventory/reservations, procurement, compatibility engine, service/repair/RMA,
component warranty duration, System warranty recalculation, ownership transfer,
claim UI/My Systems, Admin UI, serial search, data correction, removal without
replacement, reseat/reinstall, production transitions, notifications, analytics,
QR or King-Komp integration. kk-template and kk.price-update are untouched.
