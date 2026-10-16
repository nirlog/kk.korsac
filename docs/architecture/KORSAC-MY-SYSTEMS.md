# KORSAC My Systems Core v1 (0.15.0)

My Systems is an application read core for the future account experience. It
creates no records and delivers no `/account/systems/` HTTP page. The frozen
kk-template Account Experience supplies the UX/reference contract; its static
frontend is not copied into kk.korsac. kk-template and kk.price-update are unchanged.

## Membership and authentication boundary

The sole current membership rule is:

```text
b_kk_korsac_system.USER_ID = requested trusted userId
```

Order.USER_ID, Order contacts/properties, Basket, email/phone, Product, snapshot,
human SYSTEM_ID, PUBLIC_ID, secret and historical associations never select a
System for this list. Sale is not queried. Ownership history remains audit data;
it can invalidate a contradictory selected record, but cannot grant membership.
There is no second account/user/association table.

`MySystemsService::listForUser(int $userId, MySystemsPageRequest $page)` accepts a
trusted application identity in 1..2147483647. Invalid IDs fail as
my_systems_user_invalid before gateway/connection access. The service does not
authenticate, query User activity/existence or match the account to an Order.
A valid identity with no current Systems receives items=[] and hasMore=false.

**Future customer HTTP MUST derive userId from the authenticated Bitrix server
session / CurrentUser. Never accept userId, ownerUserId or actorUserId from
GET/POST, hidden form fields or client JSON.** Operator CLI input is not a future
HTTP identity contract. Authentication/login/registration/profile/logout remain
separate delivery work.

System lifecycle does not replace membership. A currently associated CANCELLED
System is still selected for its current user, with lifecycleState=CANCELLED.
Reads do not clear, reassign or reconstruct the association. No ACTIVE User,
ACTIVE System or ACTIVE Passport prerequisite is introduced for listing.

## Typed account projection

The public application result is a readonly MySystemsPage with only items and
hasMore. Each readonly MySystemView has this exact allowlist:

| Field | Meaning |
| --- | --- |
| systemId | Human KS-YYMM-NNNN identity |
| systemType | MODEL or CUSTOM |
| modelName | Immutable System creation/model snapshot |
| lifecycleState | ACTIVE or CANCELLED System lifecycle |
| transferredAt | Nullable persisted handoff ISO-8601 timestamp |
| warrantyStartedAt | Nullable persisted warranty-start ISO-8601 timestamp |
| passportPath | Nullable canonical origin-relative Passport path |
| components | List of current MySystemComponentView values |

Each component exposes only typeCode, slotLabel and name. The account DTO is a
separate type from public Passport and private operator component/history views.
Explicit toArray() methods mark CLI/output serialization boundaries; native JSON
serialization has the same safe public property set.

No numeric System/component IDs, USER_ID, Order/Basket/unit IDs, Product/Iblock
IDs, snapshot key/payload, created/updated times, serial, internal slotKey,
component intervals/history, ownership/production history, customer or staff data
is exposed. The page has no requested user ID, total/global count, pagination SQL
details, secret/hash, separate publicId or credential-verification result.

Warranty duration/end is not a backend domain yet. No warrantyEndAt, “гарантия до”,
three-year assumption, extension or remaining-month calculation is invented.

## Passport and actual hardware

For no Passport or a valid PREPARED Passport, transferredAt, warrantyStartedAt and
passportPath are all null. In particular PREPARED PUBLIC_ID is never disclosed.
For ACTIVE Passport, dates are the actual persisted values and passportPath is
exactly PassportPath::build(PUBLIC_ID), `/passport/{PUBLIC_ID}/`.
There is no absolute origin, private duplicate route or separate publicId field.
The link points to the existing public Passport.

Listing never prepares/activates a Passport, creates/rotates a credential or
verifies a secret. PassportRecord::assertValid checks persisted structure; a
missing, mismatched or malformed state is not silently converted into a usable
link. The existing public DTO/route/lifecycle remain unchanged.

Components come exclusively from b_kk_korsac_system_component, the actual physical
timeline. They are not catalog options, ordered ConfigurationSnapshot or HL
pricing data. My Systems reuses ComponentTimeline with ComponentSystemState,
including complete slot history and active serial validation, before projecting
active rows. Duplicate active slots, changed slot identity, invalid records,
overlap, an active non-latest row or an active serial conflict fail closed. It
does not blindly trust REMOVED_AT IS NULL.

Current component order is stable by SLOT_KEY then ID; the complete validator
orders slot/install time/ID. After A→B, B is visible and removed A stays only in
private storage. No serial, slotKey, numeric component ID or interval escapes.
No component, ownership, production, Sale or warranty mutation occurs.

## Query, consistency and bounds

SystemServiceFactory::mySystems() explicitly wires a MySystemsService and
read-only BitrixMySystemsGateway. The gateway capability has one findForUser()
method; it exposes no mutation, arbitrary filter or account-facing raw record API.

One non-locking SQL SELECT builds the page from the existing InnoDB tables:

1. A derived System query filters USER_ID directly, using the existing
   ix_kk_korsac_system_user index. Its fixed ordering is CREATED_AT DESC, then
   SYSTEM_ID DESC, with limit+1 and offset applied **before** child joins.
2. LEFT JOIN the single Passport and an indexed latest ownership record per
   selected parent, then that parent's component timeline.
3. Hydrate typed private read records, validate and emit the account allowlist.

The default page limit is 50, maximum 100, minimum 1; offset must be a nonnegative
PHP integer. Invalid page values fail as my_systems_page_invalid before querying.
The extra parent determines hasMore, with at most 101 parent Systems examined.
Adjacent offset pages are deterministic for stable fixture data. Concurrent
insertions can shift offsets between requests; v1 introduces no cursor protocol.

All joined data belongs to one statement's consistent InnoDB read view. There
are no per-item network queries, FOR UPDATE locks, transaction state changes or
writes. System pagination is independent of how many component rows a System
has. Complete component history in the selected parent window is read because
the established lifecycle validator needs it; its volume depends on that page's
history, not the number of Systems elsewhere in the database. The lookahead
parent is also validated before hasMore is returned.

The gateway selects only the narrow System context needed for projection and
validation. Its private MySystemReadRecord may carry internal IDs, timestamps,
Passport hash and component serials needed by existing validators; it must never
be serialized or returned to account/CLI callers. No secret is verified or logged.
SQL values are validated integer literals; table/field/ordering names are fixed.

The latest ownership lookup uses the existing (SYSTEM_INTERNAL_ID,REVISION)
index and reads one audit record, not every history row. OwnershipConsistency
checks that the selected current owner and latest record agree, including missing
history, wrong actor/event/source/parent or wrong latest owner. Full historical
ownership audit is left to ownership diagnostic boundaries, with no audit
reconstruction or N+1 history scan. This check never grants membership to a former
owner. PREPARED/no-Passport presentation remains as specified above; listing does
not reuse first-claim eligibility rules.

## Failure and privacy policy

The error allowlist is my_systems_user_invalid, my_systems_page_invalid,
my_systems_state_invalid and my_systems_read_failed. Malformed persisted System,
Passport, component or minimal ownership state fails the entire page as
state_invalid; no System is silently omitted. Driver/query/fetch failures become
read_failed, including failures after some rows have already been read.
Messages/diagnostics contain only the safe machine code, with no private context
or previous exception. Native trace arguments for trusted user/private read data
are marked SensitiveParameter. No logging is added.

No user/internal/order IDs, public token, serial, secret/hash, SQL, driver detail
or filesystem path is propagated in errors. The canonical ACTIVE Passport path
is the sole intentional public-token appearance in successful output.

## Operator CLI and read-only live gate

```bash
php local/modules/kk.korsac/tools/system.php my-systems --user=<BITRIX_USER_ID>
php local/modules/kk.korsac/tools/system.php my-systems --user=<BITRIX_USER_ID> --limit=50 --offset=0
php local/modules/kk.korsac/tests/Integration/my_systems_smoke.php
php local/modules/kk.korsac/tests/Integration/my_systems_smoke.php --user=<BITRIX_USER_ID>
php local/modules/kk.korsac/tests/Integration/my_systems_smoke.php --user=<BITRIX_USER_ID> --limit=100 --offset=0
```

CLI --user is trusted operator input only. The account service never exposes the
requested ID, including at page top level. Input is validated before domain data
access. Existing Bitrix CLI bootstrap remains required; Sale is not required.
Private tool/smoke reject non-CLI execution before bootstrap. No account route,
authentication or HTTP identity contract is delivered by these tools.

Default smoke performs read-only module/System/Passport/ownership structural
checks and component lifecycle data health. Optional --user adds the safe page.
There is no write mode; --confirm-write is rejected. Existing operator health
counts are separate from account output; listForUser() itself performs no global
health scan or other-user count.

After human architecture review, validate on the standalone installation:

```bash
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tests/Unit/run.php
php local/modules/kk.korsac/tests/Integration/my_systems_smoke.php
php local/modules/kk.korsac/tests/Integration/my_systems_smoke.php --user=<CONFIRMED_OWNER_USER_ID>
php local/modules/kk.korsac/tools/system.php my-systems --user=<CONFIRMED_OWNER_USER_ID>
php local/modules/kk.korsac/tools/system.php my-systems --user=<CONFIRMED_USER_WITH_NO_SYSTEMS>
```

Use the current server-confirmed fixtures; do not assume/hard-code their numeric
IDs in production or tests. The operator-supplied User 7 / KS-2610-0008 and
KS-2610-0009 fixture may be checked manually after confirming current ownership.
Expect existing ACTIVE Passport paths/dates and the current smoke-B SSD component,
with no removed name, serial, SSD_1, numeric component/System/Order IDs, secret/hash
or history. A separately confirmed user with no Systems must receive an empty
safe page. Do not change ownership just to obtain an empty fixture.

This cloud has no initialized Bitrix/MySQL installation; **no standalone live
read gate is certified here**. Pure query seams do not certify deployed MySQL
behavior. Customer HTTP/auth testing is not part of this read-core PR.

## Version, validation and scope

Module version/date: 0.15.0 / 2026-10-16. No schema, migration, duplicate index,
account table or uninstall change is needed. All historical migrations and
existing lifecycle/mutation boundaries stay unchanged. My Systems creates no
business data; normal uninstall preservation remains unchanged.

Tests cover canonical current association despite contradictory Order/history
fixtures, cross-user isolation, empty/cancelled lists, signed/page bounds,
stable adjacent pages, all Passport states, replaced physical hardware, private
allowlists, corrupt state, sanitized/fail-closed reads, one-query/no-write SQL and
actual CLI dispatch with invalid input rejected before data access. Existing
Passport HTML privacy/escaping/reserved-reference and frontend tests remain.

Excluded: account HTTP/template/auth/profile/orders UI, ownership requests,
claim endpoint/add System UI, unlink/transfer/release/revoke, service/support,
warranty duration/end, component history/serial UI, Admin UI, notifications,
analytics and King-Komp integration. The frozen prototype's manager-reviewed
ownership request and future unlink are separate workflows, not aliases for
secret Claim. Human architecture review is required before merge. No auto-merge.
