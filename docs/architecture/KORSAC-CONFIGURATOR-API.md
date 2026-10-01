# KORSAC Configurator API v1

## Responsibilities

`ProductConfiguration` is the current product whitelist: it says what may be
selected. `ConfigurationSelection` is the normalized user choice, validated
against that whitelist. The explicitly configured Catalog price is the price
of the DEFAULT system. `ConfigurationPriceCalculator` computes only the change from
DEFAULT. The Configurator API is the public, safe projection of these values.

Every request resolves the server-configured RETAIL Catalog price type and
pricing policy, then re-reads product properties, option rows and that explicit
Catalog price. `basePriceMinor` is the DEFAULT configuration price for the
resolved type, not a dependency on Bitrix `BASE=Y`.
The browser is never authoritative. The API is read-only and does not maintain
prices, properties, baskets or orders.

## Actions

* `kk:korsac.Configurator.get` accepts GET `iblockId` and `productId` and
  returns the normalized default selection, all 12 groups, public option
  metadata and price deltas.
* `kk:korsac.Configurator.calculate` accepts POST `iblockId`, `productId` and a
  partial `selection`; omitted groups normalize to their defaults.

Both actions use the standard `BX.ajax.runAction()` response envelope. They do
not add a second `ok` wrapper. No authentication filter is configured, so
storefront guests can use the API. HTTP method and standard Bitrix request
handling still apply.

## Pricing and data exposure

All money crosses the Catalog/HL boundary through `PriceNormalizer` and remains
integer minor units. Hardware raw prices are procurement costs and are converted
to retail by the configured policy; OS/SOFTWARE/SERVICE are direct retail.
Choice deltas and the final delta both come from the same
`ConfigurationPriceCalculator`. Public prices contain only the Catalog price
identity, base, delta, final, and (for calculate) group deltas.

**UF_PRICE is internal server pricing data. It is never exposed by Configurator
API.** HL row IDs, timestamps and absolute component prices are likewise never
part of the public projection. `UF_PUBLIC_NAME` is used when non-empty, with
`UF_NAME` only as a fallback; description is a string or `null`.

Domain error codes and safe group/XML-ID diagnostics are retained. Unexpected
throwables are reduced to `internal_error`; exception messages, SQL, paths and
stack traces are not returned.

## Read-only smoke

```bash
php local/modules/kk.korsac/tests/Integration/configurator_api_smoke.php \
  --iblock=2 --product=4

php local/modules/kk.korsac/tests/Integration/configurator_api_smoke.php \
  --iblock=2 --product=4 \
  --selection-json='{"HDD":"2 ТБ, 5400 rpm","SOFTWARE":["Microsoft Office (Trial)"]}'
```

The default smoke asserts 12 groups, zero delta and equality of base/final. A
selected-options run reports values derived from the current configured policy;
it intentionally has no obsolete raw-price fixture constants.
