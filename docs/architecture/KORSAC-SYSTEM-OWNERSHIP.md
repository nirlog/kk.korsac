# KORSAC System Ownership / Claim Core v1 (0.13.0)

## Authority and boundaries

`b_kk_korsac_system.USER_ID` is the sole CURRENT OWNER association: NULL means
unowned, a positive signed INT32 references the associated standalone KORSAC
Bitrix account. There is no second current-owner table. Ownership audit is
separate, append-only history. System identity/source, Passport credentials and
physical handoff/warranty-start timestamps stay immutable and unchanged.

Order customer, buyer, contact, recipient and payment identity are not physical
ownership. OwnershipService reads none of those fields and never infers an
owner from Sale, email/phone or Basket. Normal creation remains unowned.
Only an explicit successful claim establishes ownership in v1.

```text
Trusted authenticated KORSAC User
  + ACTIVE physical System
  + ACTIVE Passport
  + matching PUBLIC_ID / SECRET_CODE
  → first claim transaction
  → System.USER_ID + one CLAIMED audit event
```

The application/core API accepts userId because its caller is trusted. **A future
HTTP endpoint must derive userId from the authenticated server-side Bitrix
session**, never from browser USER_ID/ownerId/actorUserId parameters. A secret
does not authorize the client to select an account identity. There is no public
claim HTTP endpoint, form, login/registration, ownership enumeration or My systems
UI in this PR. CLI offers only ownership reads; it has no secret-issuing or
`ownership-claim --secret=...` command.

## Claim validation and lock order

`OwnershipService::claim(string $publicId, string $secretCode, int $userId)` uses
injected OwnershipGatewayInterface, OwnershipUserGatewayInterface and clock.
Positive identity range is 1..2147483647. Credentials reuse the existing exact
32-lowercase-hex Passport shape; no ownership token or credential copy is added.

The whole first claim runs in one D7 transaction:

1. Validate credential and target user identity shapes.
2. Identify only Passport.SYSTEM_INTERNAL_ID by public identity. This is not an
   authorization decision and reads no owner/credential/customer projection.
3. Lock the parent System FOR UPDATE as the serialization anchor.
4. Lock/read that System's Passport FOR UPDATE and recheck public/parent identity
   and persisted Passport validity.
5. Verify the locked hash with `hash_equals(persistedHash, hash('sha256', candidate))`.
6. Require ACTIVE Passport and ACTIVE System, then validate the target user.
7. Read latest history FOR UPDATE under the same parent lock; validate current
   owner/audit consistency before idempotency or owner-conflict handling.
8. For an unowned System only, assign USER_ID/UPDATED_AT conditionally and append
   the matching CLAIMED audit event. Commit before returning success.

The parent→Passport order matches Passport Core. History is ordered by REVISION
under the parent lock, not allocated through an unlocked MAX race. The user
gateway queries only ID/ACTIVE in the same standalone Bitrix connection and uses
a current locking User read; concurrent deactivation waits until the claim
transaction completes. It does not cache authorization or read customer details.
Same-System claims serialize; different Systems retain independent parent locks
but claims by the same target user may briefly share the user-row lock.

There is no detached PassportService::verifySecret authorization followed by a
blind write. Its boolean still grants no ownership; OwnershipService makes the
authoritative decision against locked data. Raw secret/hash are never copied
into ownership state, results, history, diagnostics or logs. No logging is added.
PHP 8.2 SensitiveParameter also redacts the secret argument from native traces;
controlled errors retain no chained exception or private context.

## First claim, repeat and conflict

First successful claim assigns USER_ID, updates System.UPDATED_AT and appends:

```text
REVISION = 1
FROM_USER_ID = NULL
TO_USER_ID = target user
EVENT_CODE = CLAIMED
SOURCE_CODE = SECRET_CLAIM
ACTOR_USER_ID = target user
CREATED_AT = the same instant as System.UPDATED_AT
```

Application timestamps use the existing server/Bitrix timezone convention and
are normalized to SQL DATETIME seconds. A clock before System creation or physical handoff fails as
invalid state. Passport preparation/activation/warranty dates and credentials
are not rewritten. No production status requirement or transition is introduced.

Same user + valid credential + currently active user returns claimed=false with
the existing revision/claimedAt. It performs no owner/history/timestamp write and
does not read the clock or rotate credentials. Another valid active user with
the correct credential receives system_ownership_already_claimed without the
current owner ID. Wrong/malformed secret receives credentials_invalid regardless
of whether the System is unowned, owned by the same user or owned by another.
Credential validity is established before any ownership conflict disclosure.

OwnershipClaimResult is an internal readonly result containing only numeric
System identity, human System ID, target userId, claimed flag, revision and
claimedAt. It is not a public Passport DTO. No secret/hash or Sale data is present.

## Current state, audit consistency and revisions

OwnershipConsistency checks the minimal readonly OwnershipSystemState and latest
OwnershipHistoryRecord. Unowned System must have no claimed audit state. Owned
System requires a latest valid CLAIMED / SECRET_CLAIM record whose parent and
TO_USER_ID match, whose FROM_USER_ID is NULL and actor matches the claimed user.
Contradiction is system_ownership_state_invalid; normal claim never fabricates
history, clears an owner or repairs an association.

OwnershipReadService exposes current state and ordered history for internal
operators/application use. It takes a short read transaction with the same parent
lock to avoid half-state observations and writes nothing. It validates current/
latest consistency; history reads additionally validate record identities and
strict revision ordering. User existence/active status is not rechecked for
historical reads. Deactivation/deletion after claim does not automatically clear
the association or audit. No User lifecycle event handler or FK is introduced.

Revisions are positive signed INT32. V1 has only the first-claim event; consistent
unowned state has empty history, therefore first claim is exactly revision 1.
OwnershipRevision's allocation guard accepts the signed maximum and raises
system_ownership_revision_exhausted when allocating after 2147483647; it never
wraps or resets. The bound is tested directly. Nonempty history on an unowned
System remains a consistency error instead of being treated as permission for
another claim. Same-owner repeat allocates nothing, even at the revision limit.
Future transfer/release operations need separately reviewed event/authority rules.

## Controlled mutation boundary

Ordinary SystemTable::update permits only LIFECYCLE_STATE and UPDATED_AT.
USER_ID alone or mixed with other fields is rejected as system_source_immutable;
ordinary add with non-null USER_ID is also rejected. Existing normal System
creation still inserts USER_ID=NULL, so creation/idempotency semantics remain.
No generic updateUserId/updateFields/save operation is added.

BitrixOwnershipGateway's claimUnownedSystem is a fixed conditional write under
the matching locked parent and an empty-history check:

```sql
UPDATE b_kk_korsac_system
SET USER_ID = <validated user>, UPDATED_AT = <claim instant>
WHERE ID = <locked parent>
  AND USER_ID IS NULL
  AND LIFECYCLE_STATE = 'ACTIVE'
```

Exactly one affected row is required. appendClaim accepts only the matching
first-claim record/actor/instant while that assignment is active. The gateway
rejects owner/history writes outside its controlled transaction/parent lock,
rejects a second append or arbitrary revision, and refuses to commit an owner
assignment without its audit. Owner-write, duplicate/history-insert and known
pre-commit failures roll back both halves and expose only a safe persistence error. The
factory uses the normal Bitrix connection; a narrow injectable connection seam
allows deterministic testing of actual transaction/conditional-SQL guards.

If commit acknowledgement is lost after the server commits, both owner and audit
may already exist while the caller receives persist_failed; rollback cannot undo
an acknowledged-by-server commit. The atomic pair remains consistent, and a
same-user retry with the valid credential converges idempotently. Injected unit
commit failures model failure before completion, not this network ambiguity.

OwnershipHistoryTable rejects ordinary ORM update/delete. It exposes no generic
history mutation service. Normal module uninstall preserves USER_ID/history and
all prior business data while removing executable delivery/handler artifacts.

## Migration 009 and schema health

Module version/date: 0.13.0 / 2026-10-14. Fresh install and schema.php migrate
apply `2026_10_14_009_system_ownership_core`. Migrations 001..008 and prior schema
definitions are untouched. System.USER_ID remains nullable signed INT with its
existing index; write authority changes without altering System SQL meaning.

New InnoDB table, no physical FK:

| b_kk_korsac_system_ownership_history column | Contract |
| --- | --- |
| ID | Signed INT, auto-increment primary key |
| SYSTEM_INTERNAL_ID | Signed INT NOT NULL |
| REVISION | Signed INT NOT NULL |
| FROM_USER_ID | Nullable signed INT |
| TO_USER_ID | Nullable signed INT |
| EVENT_CODE | VARCHAR(32) NOT NULL |
| SOURCE_CODE | VARCHAR(32) NOT NULL |
| ACTOR_USER_ID | Nullable signed INT |
| CREATED_AT | DATETIME NOT NULL |

Exact indexes: PRIMARY(ID), full UNIQUE
ux_kk_korsac_ownership_history_revision(SYSTEM_INTERNAL_ID,REVISION), and
ix_kk_korsac_ownership_history_system(SYSTEM_INTERNAL_ID),
ix_kk_korsac_ownership_history_to_user(TO_USER_ID),
ix_kk_korsac_ownership_history_actor(ACTOR_USER_ID). SQL nullable identities reserve
future audit shapes; v1 application records strictly require the CLAIMED shape.
There are no credential columns or copied Passport/customer fields.

OwnershipSchemaDefinition drives DDL, ORM and OwnershipSchemaSelfCheck. Health
adds ownership while preserving systems/snapshots/production/saleHandler/passports
checks; it validates signedness, type/nullability/autoincrement, InnoDB and exact
full indexes. Compatible missing secondary indexes can be added during unapplied
009; incompatible schema fails before ownership DDL. No business data is dropped.

Before any ownership DDL or migration marker, 009 counts existing non-null
System.USER_ID values. Any value (including invalid old assignments) blocks with:

```json
{"code":"ownership_legacy_user_assignments_present","count":3}
```

Only the count is reported by schema CLI, not owner/customer/credential details.
Do not clear USER_ID, reset migration state or invent CLAIMED events to pass this
gate. Operator review must choose an explicit audited correction/import strategy
outside v1; leave 009 unapplied until resolved. The expected standalone deployment
has no legitimate pre-v0.13 ownership assignments. Once 009 is recorded, repeat
migrate applies nothing and legitimate later owners do not trigger the legacy
preflight. Apply 009 successfully before enabling any programmatic claim caller.

## Errors and internal CLI

Malformed/unknown PUBLIC_ID, malformed/wrong secret all map to
system_ownership_credentials_invalid. Correct credential on PREPARED/inactive
System maps to not_claimable. Missing/inactive/out-of-range user is user_invalid;
corrupt/mismatched state is state_invalid. Other codes include already_claimed,
read_failed, persist_failed, revision_exhausted, prefixed system_ownership_.
Diagnostics contain only the machine code, never an existing owner, credential,
SQL, driver detail or path. Known errors are recreated without private context.

```bash
php local/modules/kk.korsac/tools/system.php ownership-show --system=<KS-ID>
php local/modules/kk.korsac/tools/system.php ownership-history --system=<KS-ID>
```

These operator-only reads may contain current userId and revision/claimedAt, or
safe revision/fromUserId/toUserId/event/source/actor/createdAt audit. No secret,
hash, Order/customer details or numeric component data is printed. They are not
public APIs: the CLI tool and private smoke reject non-CLI execution before
Bitrix bootstrap, so HTTP/CGI argv cannot expose reads or the stdin worker.
The public Passport DTO and /passport route remain ownership-blind;
no owner name/email, claim status or audit is added to their seven-field allowlist.

## Tests and standalone live gate

Deterministic tests exercise first claim/exact audit, same-user repeat, competing
users in both serialized orders and a winner committed between identification
and locking; malformed/wrong credentials including owned Systems; User and
signed bounds; Passport/lifecycle/corrupt-history failures; owner/history/commit
rollback; sanitized read errors; inactive-account historical reads; revision
exhaustion; strict ORM guards and actual gateway refusal of commit without audit;
migration legacy preflight/idempotency/schema incompatibility. Existing Passport,
public HTML/privacy and frontend suites remain unchanged/passing. All PHP lint
and unit tests run on actual PHP 8.2, not a newer syntax runtime.

This cloud environment has no initialized Bitrix/MySQL installation. **Live
ownership writes and real database concurrency have not been run or certified.**
Human architecture review is required before controlled ownership write tests.
After review, on the standalone test installation:

```bash
php local/modules/kk.korsac/tools/schema.php migrate
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tests/Unit/run.php
php local/modules/kk.korsac/tests/Integration/system_ownership_smoke.php
php local/modules/kk.korsac/tests/Integration/system_ownership_smoke.php --system=<EXISTING_TEST_SYSTEM>
php local/modules/kk.korsac/tools/system.php ownership-show --system=<EXISTING_TEST_SYSTEM>
php local/modules/kk.korsac/tools/system.php ownership-history --system=<EXISTING_TEST_SYSTEM>
```

Default smoke checks module and ownership/System/Passport schemas without domain
writes. Optional --system adds consistency-checked safe internal state. Write
mode requires an ACTIVE unowned dedicated System with no Passport/history plus
an existing ACTIVE test User; it preflights those requirements before preparation.

```bash
php local/modules/kk.korsac/tests/Integration/system_ownership_smoke.php --system=<FRESH_TEST_SYSTEM_WITHOUT_PASSPORT> --user=<ACTIVE_TEST_USER_ID> --confirm-write
php local/modules/kk.korsac/tests/Integration/system_ownership_smoke.php --system=<ANOTHER_FRESH_TEST_SYSTEM_WITHOUT_PASSPORT> --user=<ACTIVE_TEST_USER_ID> --confirm-write --concurrency=4
```

The parent prepares/activates Passport and retains the one-time secret only in
memory. It verifies claim/owner/exact revision-1 audit, same-user repeat without
timestamp/history change, unchanged Passport and ownership-blind public DTO.
Output reports safe state and booleans, never raw secret/hash. The optional
2..8 real PHP workers receive only public identity/User IDs in argv; their secret
is delivered solely through an anonymous stdin pipe, never argv/env/stdout/logs
or temporary files. Exactly one worker must claim and others repeat idempotently;
the parent verifies one owner/history row. proc_open and the deployed PHP config
are required. Unit fake races are not proof of live MySQL locking.

Successful smoke permanently retains Passport activation and ownership/audit.
It does not roll back successful business history, clear USER_ID, delete records
or reissue secrets. Use a separate fresh disposable physical System for each
write/concurrency run. A pre-existing Passport/owner/history fails the fixture
gate; no fixture reset command is provided. A failed test after preparation may
leave a prepared/activated Passport for operator investigation. Do not auto-merge.

## Scope

Implemented: backend ownership first claim, active-user validation, atomic owner/
audit, private consistency/read operations, strict USER_ID/history mutation
authority, migration/health/CLI reads and controlled smoke. No public claim HTTP,
authentication UI, client-supplied owner identity, transfer/release/revocation,
secret reissue, email/SMS, Order-customer assignment, admin override, service,
warranty duration/claims, QR generation, production changes, analytics or
King-Komp runtime dependency. kk-template and kk.price-update are untouched.
