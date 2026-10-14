# Public System Passport HTTP Delivery v1 (0.12.0)

## Backend and transport boundary

Delivery depends on the merged v0.11 [Passport Core](KORSAC-SYSTEM-PASSPORT.md),
including the distinction between public not-found and operational read failure.
A physical System and its credentials/lifecycle remain independent of HTTP.
The page calls `SystemServiceFactory::publicPassports()` through
PublicPassportHttpPresenter. It never reads System/Passport/component tables,
Orders, Basket or snapshots itself. No database schema or migration is added;
migrations 001..008 and the public DTO are unchanged. Version date is 2026-10-14.

| Query outcome | HTTP | Page state | Public content |
| --- | --- | --- | --- |
| Valid ACTIVE DTO | 200 | ACTIVE | Explicit PublicPassportDto fields |
| Malformed/unknown PUBLIC_ID or valid PREPARED Passport | 404 | NOT_FOUND | «Паспорт системы не найден.» |
| system_passport_read_failed or other operational/domain failure | 500 | ERROR | «Паспорт временно недоступен. Попробуйте открыть страницу позже.» |
| Unexpected Throwable, bootstrap/component/rendering failure | 500 | ERROR | Same generic error, no internal reason |

The immutable transport view contains only status, state and a public DTO or null.
Error views contain neither exception/context nor Passport data. Preparation,
activation, one-time secret issuance and verification behavior stay in Core.

## Canonical route and request identity

```text
/passport/{32-lowercase-hex-PUBLIC_ID}/
```

The installed Bitrix URL rewrite is owned by ID `kk:korsac.passport`:

```text
CONDITION = #\A/passport/([a-f0-9]{32})/?(?:\?[^\#\r\n]*)?\z#D
RULE      = PUBLIC_ID=$1
PATH      = /passport/index.php
```

The optional query tail supports Bitrix versions that match the entire
REQUEST_URI instead of only its path. The path remains narrowly anchored;
uppercase/wrong-length/human IDs, encoded slashes, extensions and extra segments
do not match. No arbitrary Passport path wildcard is installed. Original path
parsing does not URL-decode a token, inspect GET/POST credentials or trust a
PUBLIC_ID query override. Direct `/passport/index.php?PUBLIC_ID=...` is not an
alternative public identity route and returns the same generic not-found.

Valid token paths without their slash redirect permanently (301) to the existing
PassportPath relative path. The entire incoming query is discarded. The redirect
contains no authority/Host header, secret, arbitrary query or fragment. GET/HEAD
requests deliver a page; other methods cannot supply a Passport identity and
receive the same generic 404. No POST action or secret/claim input exists.

Malformed paths rejected by the narrow rule fall through to the installation's
normal Bitrix/web-server 404 handling. This PR does not replace the site's global
404 handler or add a catch-all route. The live gate must verify that handler's
404 status, safe body and privacy headers as well as the routed module responses;
those site-level behaviors cannot be certified by the route unit tests. When the
endpoint/component receives malformed PUBLIC_ID directly, its 404 uses the same
module policy and generic content as unknown/PREPARED lookup.

## Bitrix runtime and response policy

Canonical sources:

```text
install/public/passport/index.php
install/components/kk/korsac.passport/.description.php
install/components/kk/korsac.passport/component.php
install/components/kk/korsac.passport/templates/.default/template.php
install/components/kk/korsac.passport/templates/.default/style.css
```

The endpoint bootstraps normal prolog_before, loads kk.korsac, captures the
component before the site header, then includes normal Bitrix header/footer.
This establishes status, title, robots metadata and registered component CSS
before the header is rendered. Component input is only PUBLIC_ID; CACHE_TYPE=N
and no StartResultCache prevent stale physical component truth.

For every module page state (200, 404, 500), and canonical redirects:

```text
X-Robots-Tag: noindex, nofollow, noarchive
Referrer-Policy: no-referrer
Cache-Control: no-store
```

The component sets equivalent robots page metadata. A final header callback
preserves the resolved status/privacy policy when Bitrix epilog changes headers.
Site/CDN/proxy behavior still needs the real HTTP gate. No sitemap registration,
catalog canonical URL, analytics, external scripts/fonts/resources or JavaScript
is added by the component. Existing global site infrastructure remains outside
module ownership.

The endpoint explicitly catches Throwable and converts reportable runtime
warnings to a safe exception boundary; deprecations are not printed. Failed
partial rendering is discarded. display_errors is disabled as defense in depth,
not as the sole error boundary. If normal bootstrap or header/footer itself
fails, a minimal generic Russian HTML error document replaces the partial page
without including the failing site templates. It contains robots metadata and
the same response policy. No exception message/class, SQL, path, trace or machine
code is emitted into HTML, and no private diagnostic logging is introduced.

## Rendering and privacy

The component template reads only the dedicated public view and DTO. It renders
KORSAC branding, one meaningful H1, human System ID/model, a local MODEL/CUSTOM
label, separate physical transfer/warranty-start dates and component rows in
authoritative DTO order. Only slotLabel and component name are rendered.
Dates retain their DTO timezone/date and display dd.mm.YYYY with time/datetime
semantics. Every dynamic text/attribute is escaped with ENT_QUOTES/ENT_SUBSTITUTE.

Zero recorded actual components is valid and displays «Данные о комплектующих
пока не внесены.» No components are reconstructed from catalog defaults, Order or
snapshot data. No warranty duration/end, owner identity, service eligibility or
Order status is inferred. The small CSS is scoped to kk-korsac-passport, works
without JavaScript, uses a readable phone layout and includes basic print rules.
It has no kk-template or external framework dependency.

Numeric System/catalog/Order/Basket/User/component identities, snapshot keys or
payloads, raw secret/hash, serials, internal slots and histories never reach the
public view or HTML. Existing Core privacy tests remain, and rendering tests use
distinctive private-rich domain fixtures to detect accidental exposure.

## Install, existing installations, health and removal

PassportDeliveryDefinition is a fixed five-file manifest. PassportDeliveryFiles
proves existing ownership using the literal source marker
`kk.korsac:public-passport-delivery:v1`; it refuses symlink targets/parents and
foreign existing files. Already current owned files are left untouched. Missing
files are copied atomically; marked outdated files can be upgraded. No whole
/passport or /local/components/kk directory is recursively overwritten/deleted.

PassportDeliveryService preflights all files/routes before writing. The canonical
owned route is kept exactly once; duplicate/malformed owned entries are repaired
by replacing only entries with the exact component ID. Other application routes
are preserved. A foreign entry using the same declared condition or endpoint
causes a safe conflict instead of being overwritten. Health is read-only and
detects missing/outdated/foreign files, missing/duplicate/malformed owned route
and route conflicts. Install failures retain completed safe file copies for an
idempotent retry; installation is not a database transaction.

Fresh DoInstall calls the focused service through InstallFiles. Existing
installations do not need module reinstallation or migration 009:

```bash
php local/modules/kk.korsac/tools/system.php passport-delivery install
php local/modules/kk.korsac/tools/system.php passport-delivery check
```

Commands use the current Bitrix SITE_ID or the single active default site.
For an explicit standalone site context, append `--site=s1` using its actual
two-character ID. The service operates on that site's rewrite registration and
the current DOCUMENT_ROOT. It does not change unrelated site routes or introduce
multi-site provisioning. Install/check have a nonzero exit on failure; check
never repairs anything. Updating module sources alone does not install artifacts.

Normal UnInstallFiles removes the selected site's module-owned rewrite and only
manifest files still proven module-owned; custom files and other kk components
remain. Empty component directories may be removed; directories containing
unrelated files remain. Foreign/modified files without the marker are preserved.
Uninstall never deletes Passport/System/component history, public identities,
secret hashes or transfer/warranty-start dates. It uses no business repository or
DDL. The prior configurator remains separately copied/removed at its exact path.

Safe operational errors include passport_delivery_file_conflict,
passport_delivery_route_conflict, passport_delivery_read_failed,
passport_delivery_install_failed and passport_delivery_remove_failed. No driver
error or absolute filesystem path is appended to their machine diagnostics.

## Relative URLs, public origin and QR contract

The HTTP page and canonical redirect need only relative PassportPath; no public
origin setting or absolute URL builder is added in v1. Never derive an absolute
origin from HTTP_HOST. A future origin configuration must accept HTTPS scheme +
host only, an optional explicit test port, and no userinfo/path/query/fragment.
Unset origin does not prevent page rendering; absolute QR payload remains
unavailable until a validated origin is configured in that later layer.

Future QR payload is exactly:

```text
https://<configured-public-origin>/passport/{PUBLIC_ID}/
```

No secret/hash, numeric identity or Order information belongs in the payload.
No QR image or third-party QR library is generated by this PR.

## Validation and remaining live gate

Pure tests cover presenter status/error privacy, uniform header policy, actual
template escaping/content/private-value exclusion, empty components, exact path
and query-free redirect, installer idempotency/repair/conflicts/read-only health
and ownership-preserving removal. Filesystem tests exercise actual copies and
symlink refusal in disposable directories. Full module PHP lint and all tests
run on actual PHP 8.2; existing browser suites remain unchanged.

Read-only smoke checks module/delivery registration and optionally resolves an
operator-supplied ACTIVE public identity through the same presenter/service:

```bash
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tools/system.php passport-delivery check
php local/modules/kk.korsac/tests/Unit/run.php
php local/modules/kk.korsac/tests/Integration/system_passport_http_smoke.php
php local/modules/kk.korsac/tests/Integration/system_passport_http_smoke.php --public-id=<ACTIVE_PUBLIC_ID>
```

The smoke issues no HTTP requests or domain writes. `expectedResponseHeaders`
reports policy, and `realHttpRoutingChecked=false` explicitly distinguishes it
from a web-server test. No fixture PUBLIC_ID is hard-coded. Existing ACTIVE test
Systems KS-2610-0006/0007 may be used by the operator supplying their public IDs.

**Human architecture review is required before the live HTTP gate and merge.**
This cloud environment has no initialized Bitrix/web server, so real standalone
Bitrix route/header/template integration has not been run or certified here.
After review and deployment to the standalone test installation:

```bash
php local/modules/kk.korsac/tools/system.php passport-delivery install
php local/modules/kk.korsac/tools/system.php passport-delivery check
php local/modules/kk.korsac/tools/schema.php check
php local/modules/kk.korsac/tests/Unit/run.php
php local/modules/kk.korsac/tests/Integration/system_passport_http_smoke.php --public-id=<ACTIVE_PUBLIC_ID>
```

Set these public-only test values to operator-supplied fixtures. No secret input
is needed; PREPARED must be an existing prepared fixture, not created by HTTP.

```bash
PASSPORT_ORIGIN='https://korsac.example'
ACTIVE_PUBLIC_ID='<ACTIVE_PUBLIC_ID>'
PREPARED_PUBLIC_ID='<PREPARED_PUBLIC_ID>'
UNKNOWN_PUBLIC_ID='<CONFIRMED_UNKNOWN_32_LOWERCASE_HEX>'

curl --silent --show-error --dump-header /tmp/passport-active.headers --output /tmp/passport-active.html --write-out '%{http_code}\n' "$PASSPORT_ORIGIN/passport/$ACTIVE_PUBLIC_ID/"
curl --silent --show-error --dump-header /tmp/passport-unknown.headers --output /tmp/passport-unknown.html --write-out '%{http_code}\n' "$PASSPORT_ORIGIN/passport/$UNKNOWN_PUBLIC_ID/"
curl --silent --show-error --dump-header /tmp/passport-prepared.headers --output /tmp/passport-prepared.html --write-out '%{http_code}\n' "$PASSPORT_ORIGIN/passport/$PREPARED_PUBLIC_ID/"
curl --silent --show-error --dump-header /tmp/passport-malformed.headers --output /tmp/passport-malformed.html --write-out '%{http_code}\n' "$PASSPORT_ORIGIN/passport/not-a-public-id/"
curl --silent --show-error --dump-header /tmp/passport-redirect.headers --output /tmp/passport-redirect.html --write-out '%{http_code}\n' "$PASSPORT_ORIGIN/passport/$ACTIVE_PUBLIC_ID?unused=discard-me"
```

Expected statuses are 200, 404, 404, 404 and 301 respectively. Inspect header
files for the three exact policies and the redirect's relative Location without
query. Inspect HTML for safe content, generic identical unknown/PREPARED bodies,
no private/machine diagnostics and correct escaping. The malformed request must
also be checked against the site's native 404 handler as explained above.
Do not simulate a DB outage on production; a controlled test-installation failure
can separately verify the generic 500 and the same headers. No HTTP smoke writes
credentials, activates Systems or deletes business history. Do not auto-merge.

## Scope

Implemented: public backend DTO delivery, minimal scoped utility HTML/CSS,
normal Bitrix component/endpoint, narrow rewrite, status/privacy policy,
installation/repair/check/removal, pure tests and read-only smoke.
Ownership, User assignment, login/registration, claim/reissue flows, service,
component replacement, warranty duration/end/claims, Order/customer data,
production changes, payments/delivery, notifications, analytics, sitemap/SEO
landing pages, QR images, kk-template redesign and King-Komp integration remain
outside this PR. kk-template and kk.price-update are untouched.
