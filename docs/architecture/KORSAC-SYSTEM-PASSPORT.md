# KORSAC System Passport Core v1 (0.11.0)

## Four identities and the standalone boundary

A physical System exists independently from its Passport. All data and referenced
Orders/Users/Catalog belong to the standalone KORSAC Bitrix installation/database;
King-Komp is not a runtime dependency. Neither `kk-template` nor `kk.price-update`
is changed.

| Identity | Meaning and boundary |
| --- | --- |
| SYSTEM_INTERNAL_ID | Private signed INT32 System.ID; parent relational identity, never public |
| SYSTEM_ID | Existing immutable human physical ID, KS-YYMM-NNNN; public display is allowed, never a URL token |
| PUBLIC_ID | Immutable opaque public web identity: 16 CSPRNG bytes encoded as 32 lowercase hex characters |
| SECRET_CODE | Independent private 16-byte/32-lowercase-hex credential, issued once; verification alone grants no ownership |
| SECRET_CODE_HASH | Only persisted secret representation: lowercase SHA-256 of the 32-character SECRET_CODE string |

`SecurePassportCredentialGenerator` uses separate `random_bytes(16)` calls for
public identity and secret. It accepts no System/Order/product/date/counter input.
Application boundaries validate both output shapes. If independently generated
values coincide, both are regenerated rather than publishing the private value.
No password KDF is added: this is a 128-bit random credential, not a human password.
Random/driver failures expose only safe codes.

Raw SECRET_CODE may exist temporarily in application memory and be returned once
to the successful creator after transaction commit. It is never copied into a
PassportRecord, database field, System, snapshot, production history, public DTO,
URL/path/query/fragment/QR, EventLog, health or ordinary diagnostics. Hash values
also stay out of public/ordinary operational output. Schema health may identify
column/index names, but never credential values.

## Lifecycle and persistence

```text
System
  → preparePassport()
  → PREPARED (credentials exist; public lookup returns not-found)
  → actual physical handoff / activatePassport()
  → ACTIVE (handoff and warranty start recorded; public DTO available)
```

Only PREPARED and ACTIVE exist in v1. There is no deactivation, revocation, expiry,
ownership state or automatic Passport creation from Sale. Production status and
System.LIFECYCLE_STATE remain separate; Passport activation neither requires nor
sets READY_FOR_DELIVERY or any other production status. No User is assigned.

Migration `2026_10_13_008_system_passport_core` creates
`b_kk_korsac_system_passport`, InnoDB with no physical foreign key:

| Column | SQL contract |
| --- | --- |
| SYSTEM_INTERNAL_ID | Signed INT NOT NULL primary key (positive 1..2147483647) |
| PUBLIC_ID | CHAR(32) NOT NULL |
| SECRET_CODE_HASH | CHAR(64) NOT NULL |
| STATE | VARCHAR(16) NOT NULL |
| TRANSFERRED_AT | Nullable DATETIME |
| WARRANTY_STARTED_AT | Nullable DATETIME |
| PREPARED_AT | DATETIME NOT NULL |
| UPDATED_AT | DATETIME NOT NULL |

Required full indexes are PRIMARY(SYSTEM_INTERNAL_ID), UNIQUE
ux_kk_korsac_passport_public_id(PUBLIC_ID), UNIQUE
ux_kk_korsac_passport_secret_hash(SECRET_CODE_HASH),
ix_kk_korsac_passport_state(STATE) and
ix_kk_korsac_passport_transferred(TRANSFERRED_AT). Prefix indexes cannot satisfy
these definitions. One System has at most one Passport; public identity and
secret digest uniqueness are enforced globally by the database.

PassportSchemaDefinition centrally drives DDL, D7 ORM mappings and health.
Migrations 001..007 and prior schema definitions remain unchanged. Ordinary
PassportTable ORM updates/deletes are rejected for every field. There is no
arbitrary Passport update API. The repository's controlled activation writes only
STATE, TRANSFERRED_AT, WARRANTY_STARTED_AT and UPDATED_AT with a conditional
PREPARED/null-date predicate, so it cannot rewrite already active handoff history.
PUBLIC_ID, SECRET_CODE_HASH and PREPARED_AT are never updated.

## Prepare, parent locking and credential collisions

`PassportService::preparePassport(int $systemInternalId)` requires a positive
signed INT32 identity, an existing System and current System lifecycle ACTIVE.
It starts a D7 transaction and locks the current parent System row FOR UPDATE.
The existing parent is the stable serialization anchor even before a Passport
row exists; then the Passport row is read FOR UPDATE. No filesystem/memory/static
lock participates in correctness.

An existing valid Passport is returned unchanged with `created=false` and
`secretCode=null`, including when already ACTIVE. There is no generator/clock
call, timestamp change or record write. A missing Passport receives independently
generated credentials and only their digest is persisted, with PREPARED state,
NULL handoff/warranty start, and PREPARED_AT=UPDATED_AT=current application time.
`PassportPreparationResult` contains the internal record, created flag and
one-time raw secret; it is a sensitive internal result, not a public serializer.

Insert uniqueness is authoritative. The repository classifies only an actual
D7 SQL duplicate-entry reason naming the declared public-id or secret-hash UNIQUE
index as an internal credential-collision signal. A primary-key failure or
unrelated driver/validation error is a safe persistence failure, not a guessed
collision. At most `MAX_CREDENTIAL_ATTEMPTS=5` attempts regenerate both values
while retaining the same parent lock/transaction. Exhaustion produces
`system_passport_token_collision`; unrecognized insertion failure produces
`system_passport_persist_failed`. No query, duplicate value or driver reason is
propagated to diagnostics. The two declared indexes' names are part of this
controlled schema/driver contract.

Same-System concurrent prepares converge to one row/public ID and only the
committed creator receives the secret. Different Systems have different lock
anchors and share only global credential uniqueness. If commit acknowledgement
or output delivery fails, a row may exist while its raw secret is unavailable;
repeat intentionally cannot reproduce it. Secret rotation/reissue is future
work. Do not overwrite hashes or drop records to recover a lost credential.

## Verification is not ownership

`verifySecret(string $publicId, string $secretCode): bool` validates shapes,
finds the Passport, hashes the candidate and compares with `hash_equals()`.
Malformed, unknown, mismatching or unreadable credentials return false without
storage details. The operation logs nothing, mutates nothing and returns only a
boolean. PREPARED credentials may be verified internally. Verification grants no
ownership, User association, public write action or other authority. The separate
v0.13 [OwnershipService](KORSAC-SYSTEM-OWNERSHIP.md) grants the first association
only from an ACTIVE Passport, valid locked credential and active trusted User;
it does not use this detached boolean as permission to write ownership. Public
Passport projection/delivery stays ownership-blind.

## Activation at physical handoff

`activatePassport(int $systemInternalId, ?DateTimeImmutable $transferredAt = null)`
locks the parent and Passport in a transaction, requires an existing ACTIVE
physical System and an already prepared/active Passport, and never auto-prepares.

First activation changes Passport state to ACTIVE. TRANSFERRED_AT is the actual
handoff instant (application now if omitted), WARRANTY_STARTED_AT is exactly the
same instant and UPDATED_AT is activation time. Public ID, secret digest and
prepared time remain unchanged. Handoff must not be before System.CREATED_AT or
in the future relative to the injected/current clock. Order creation/payment,
production status, snapshot or System creation time never supplies a warranty
start fallback. No warranty duration/end date/claim calculation exists in v1.

Repeated activation without an explicit instant returns the existing record
unchanged, without reading the clock or rewriting UPDATED_AT. Explicitly supplying
the same instant (including another UTC offset representing it) is idempotent;
a different instant produces `system_passport_activation_conflict`. No subsequent
Core operation changes historical transfer/warranty dates.

All workers must use the same PHP/Bitrix server application timezone convention
as System core. Explicit input is converted to the current application clock's
timezone before persistence. SQL DATETIME stores seconds: generated clock times
are normalized to seconds, and explicit finer-than-second handoff instants are
rejected to preserve exact immutable identity rather than silently truncate it.
CLI accepts strict ISO-8601 with seconds and an explicit UTC offset or Z, rejects
invalid/calendar-overflow dates and does not accept relative expressions such as
“tomorrow”. This storage-precision restriction is deliberate, not a warranty policy.

## Explicit public DTO and visibility

`PublicPassportService::find($publicId)` returns a dedicated PublicPassportDto.
Malformed/unknown IDs and valid PREPARED passports have the same public code
`system_passport_not_found`. Repository read failures, missing parent Systems and
invalid/corrupt persisted Passport data instead produce `system_passport_read_failed`.
The public projection never propagates underlying machine/driver errors or private
diagnostics. Only valid ACTIVE Passports resolve. It never serializes PassportRecord,
SystemRecord or SystemComponent.

The exact public field allowlist is:

```json
{
  "publicId": "0123456789abcdef0123456789abcdef",
  "systemId": "KS-2610-0007",
  "systemType": "MODEL",
  "modelName": "KORSAC PLAY 1440",
  "transferredAt": "2026-10-13T10:00:00+03:00",
  "warrantyStartedAt": "2026-10-13T10:00:00+03:00",
  "components": [{"typeCode": "SSD", "slotLabel": "SSD 1", "name": "Actual disk"}]
}
```

Components come from the existing SystemComponentRepository with activeOnly=true;
the projection also filters REMOVED_AT and parent identity explicitly. It includes
only actual currently installed type/slot label/name, not ordered snapshot options
or removed component history. No second component table or copied component
payload is created.

Private fields/values excluded from DTO: numeric System identity, Order/Basket/unit
IDs, User/catalog IDs, snapshot key/payload, raw secret/digest, component ID,
SERIAL_NUMBER/internal slot key, production history, service data, customer
name/contact/address and payment/delivery data. No query to a customer/account or
production-history repository is needed to build this allowlist.

## Canonical public path and separate HTTP delivery

`PassportPath::build($publicId)` validates exact lowercase 32-hex shape and builds:

```text
/passport/{PUBLIC_ID}/
```

Use PUBLIC_ID, never SYSTEM_ID or numeric System.ID. SECRET_CODE/digest must never
be passed as a path, query/fragment or QR value. Prepared public identity can be
printed before handoff, but lookup stays not-found until activation. Core v1
reserved the contract; v0.12 now implements separate Bitrix HTTP/component
delivery using this same query boundary. See
[KORSAC-PUBLIC-PASSPORT-DELIVERY.md](KORSAC-PUBLIC-PASSPORT-DELIVERY.md) for
200/404/500 semantics, privacy headers, installation and the real HTTP gate.
QR image generation remains future work.

## CLI and sensitive issuing output

```bash
php local/modules/kk.korsac/tools/system.php passport-prepare --system=<TEST_SYSTEM>
php local/modules/kk.korsac/tools/system.php passport-show --system=<TEST_SYSTEM>
php local/modules/kk.korsac/tools/system.php passport-activate --system=<TEST_SYSTEM>
php local/modules/kk.korsac/tools/system.php passport-activate --system=<TEST_SYSTEM> --transferred-at=2026-10-13T10:00:00+03:00
php local/modules/kk.korsac/tools/system.php passport-public --public-id=<PUBLIC_ID>
```

**passport-prepare is an explicit credential-issuing command.** Only first
successful creation includes raw `secretCode` in its JSON result; that output is
sensitive. Keep it through an authorized secure handoff process; do not copy it
to logs, chat, PRs, URLs or QR payloads. Repeat returns `created=false`, the same
public identity/state, and `secretCode=null`. No CLI prints a secret hash and no
rotation/reissue operation exists.

passport-show and activation use PassportInternalView's fixed allowlist:
systemId/publicId/state/preparedAt/transferredAt/warrantyStartedAt. There are no
Order IDs, numeric parent identity, raw secret, hash or serial numbers. A System
without a Passport shows null Passport fields. passport-public calls exactly the
public query service described above, not a separate CLI-specific serializer.
Passport commands do not require loading Sale; existing non-Passport commands
retain their Sale requirement. The module's established bootstrap/runtime module
requirements remain otherwise unchanged. Errors are JSON safe machine codes with
nonzero exit status; no secret or driver reason is appended.

## Migration, health and uninstall

Module version is 0.11.0, dated 2026-10-13. Fresh DoInstall and `schema.php migrate`
apply 008 via a separate PassportMigrationService/BitrixPassportTableGateway.
Upgrade from 0.10 adds 008 only; repeat applies nothing. Incompatible existing
columns, signedness, nullability, engine or indexes fail safely before DDL;
compatible missing secondary indexes may be added. MySQL DDL implicit commits
mean safe completed DDL is retained for a retry, never silently dropped/rebuilt.

`schema.php check` adds a `passports` section while retaining HL, snapshots,
System, production and persistent Sale-handler checks. Diagnostics report metadata
names/types incompatibility, not credential values. Prefix indexes cannot satisfy
the public/digest UNIQUE contract. Existing Sale event registration/behavior is
unchanged by Passport Core. Normal uninstall preserves Passport identities,
digests, handoff dates and warranty starts along with prior business data.

Safe errors include system_passport_not_found, system_passport_state_invalid,
system_passport_system_inactive, system_passport_token_generation_failed,
system_passport_token_collision, system_passport_persist_failed,
system_passport_read_failed, system_passport_transfer_time_invalid and
system_passport_activation_conflict. Ordinary ORM mutation fails
system_passport_immutable; malformed internal numeric source uses existing
system_source_invalid. Public lookup keeps the three intended not-found cases
indistinguishable while normalizing operational/data failures to
system_passport_read_failed without diagnostic context or chained exceptions.

## Tests and required live gate

The complete unit suite and whole module lint run on actual PHP 8.2. Passport
tests cover byte-generation shapes/independence/failures, SHA-256-only storage,
public/digest collision retries and bounds, first-row locking protocol/idempotency,
missing/inactive/signed-boundary Systems, boolean constant-time verification,
explicit activation and immutable timestamp conflicts, precision/timezone input,
private-rich DTO fixtures/current components/path, ORM guard, migration and health.
Unit fakes do not prove real database locking/uniqueness or deployed D7 compatibility.

This cloud checkout has no initialized Bitrix database. **Live Passport integration
has not been run here. Human architecture review is required before live write
tests and merge.** After review, on the standalone test installation:

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tests/Unit/run.php
php local/modules/kk.korsac/tests/Integration/system_passport_smoke.php
php local/modules/kk.korsac/tools/system.php passport-show --system=<TEST_SYSTEM>
php local/modules/kk.korsac/tests/Integration/system_passport_smoke.php --system=<FRESH_DEDICATED_TEST_SYSTEM> --confirm-write
```

Default smoke checks module availability and Passport/System/production schema
without domain writes; optional --system outputs only safe internal state. Write
mode requires a **fresh ACTIVE dedicated test System without a Passport**, because
existing plaintext intentionally cannot be recovered/reverified. It prepares and
verifies the one-time secret in memory, confirms hash-only application storage,
repeats prepare without reissue, verifies PREPARED is not public, activates,
verifies safe public DTO and handoff=warranty start, repeats activation unchanged
and checks exactly one row. It persists the test Passport/activation; it does not
roll back business history, delete rows or reissue a credential. Its output has
`secretVerified=true`, never the raw secret or hash.

Optional real first-prepare concurrency:

```bash
php local/modules/kk.korsac/tests/Integration/system_passport_smoke.php --system=<ANOTHER_FRESH_TEST_SYSTEM> --confirm-write --concurrency=4
```

2..8 independent PHP workers prepare concurrently. Workers verify the creator's
secret internally and emit only public identity/created/boolean verification;
no secret is transported through stdout/IPC/logs. The parent checks convergence
and exactly one creator/row, then performs activation/privacy/idempotency checks.
proc_open and the same deployed PHP configuration are required. A pre-existing
Passport fails the fresh-fixture prerequisite rather than being reset. Do not
claim this live gate from fake race tests; record actual server outcomes and keep
auto-merge disabled.

## Scope

Implemented: independent Passport persistence/identities/secret verification,
prepare/explicit physical handoff, warranty start only, safe public query/path,
backend CLI/migration/health/smoke. Public HTML/HTTP delivery is implemented
separately in v0.12; it does not change this Core lifecycle/schema/DTO contract.
Ownership first claim/audit is implemented separately in v0.13 without changing
this Passport contract. QR images, public claim UI/My systems, ownership transfer, secret reissue UI,
service/repair/upgrade/component replacement, warranty duration/end/claims,
production transition policy, payment/delivery/inventory/procurement/customer
notifications, admin UI or King-Komp integration is added.
