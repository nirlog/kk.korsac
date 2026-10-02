# KORSAC configured Basket and snapshot v1

## Boundary and authoritative quote

`ConfiguredProductPricingService` is the single orchestration used by both the public Configurator and Cart. Every `Cart.add` rereads the product whitelist, resolves the server-side RETAIL price type and policy, reads the current Catalog price, normalizes the selection, and recalculates the price. Browser price, currency, channel, policy, names, and snapshot data are never inputs.

`kk:korsac.Cart.add` is POST-only. Authentication is removed for guest FUSER carts, while the normal D7 CSRF/session prefilter remains enabled. Quantity is fixed at one.

## Immutable snapshot v1

Table `b_kk_korsac_config_snapshot` stores an insert-only commercial snapshot with a random 32-byte hex key, SHA-256 of the exact canonical JSON, site/product/price metadata, integer minor-unit amounts, payload, and creation time. Migration `2026_10_02_004_configuration_snapshot` creates the table and unique key, product, and created-at indexes idempotently. Run it on an installed environment with:

```bash
php local/modules/kk.korsac/tools/schema.php migrate
```

The JSON uses `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR` and deterministic builder ordering. It includes schema version 1, all twelve normalized selection groups, price type/currency/base/delta/final/group deltas, and add-time `xmlId`/display names. It deliberately excludes procurement prices.

## Basket and Order contract

Each add creates a separate Basket row owned by `MODULE=kk.korsac`, with
`PRODUCT_PROVIDER_CLASS=KK\Korsac\Cart\KorsacCatalogProvider`. Its price is the exact decimal representation of
`finalPriceMinor`, with `BASE_PRICE=PRICE`, `DISCOUNT_PRICE=0`, and `CUSTOM_PRICE=Y`. Machine properties are
`KORSAC_CONFIGURED`, `KORSAC_SNAPSHOT_KEY`, `KORSAC_SNAPSHOT_HASH`, and `KORSAC_SNAPSHOT_VERSION`. Selected single groups
use `KORSAC_<GROUP>`; SOFTWARE and SERVICE use one bounded property per item (`_001`, `_002`, ...). Standard Sale
Basket-to-Order copying preserves these properties; checkout does not reprice or reconstruct the configuration.

Configured rows use `KorsacCatalogProvider`, which delegates normal availability, quantity, dimensions, weight, measure,
catalog metadata, and stock behavior to Bitrix `CatalogProvider`. It overrides only configured price data after validating
`KORSAC_CONFIGURED=Y` and `KORSAC_SNAPSHOT_KEY` against the immutable snapshot. This keeps `BASE_PRICE` and `PRICE`
equal to the snapshot final unit price through Basket refresh and `Order::setBasket()`. A missing, corrupt, wrong-product,
or wrong-currency snapshot fails explicitly and never falls back to the current Catalog DEFAULT price. Non-KORSAC rows
retain unmodified parent-provider behavior.

The configured `PRICE_TYPE_ID` comes from the immutable snapshot. `PRODUCT_PRICE_ID` is intentionally cleared because the
configured final price is not a row in `b_catalog_price`; no synthetic Catalog price row is created. Loading the persisted
Basket module in a cold request executes `kk.korsac/include.php`, which deliberately loads the required `catalog` module
before the KORSAC provider class extends Bitrix `CatalogProvider`. It does not globally load `sale`.

Provider currency is taken from the normalized `CatalogProvider` context, not from an incidental product or parent price
row. For the standard Sale response shape, the KORSAC fields replace exactly
`PRODUCT_DATA_LIST[productId].PRICE_LIST[basketCode]`; aggregate availability and catalog data remain untouched. A direct
top-level price-row shape is supported as a compatibility path without overwriting a product aggregate.

If snapshot persistence fails, Basket is untouched. If Basket persistence fails, the newly inserted unattached snapshot is deleted on a best-effort basis. Successfully attached and historical snapshots are never updated or removed by normal uninstall.

## Smokes

Read-only quote/snapshot/property projection:

```bash
php local/modules/kk.korsac/tests/Integration/cart_quote_smoke.php --iblock=2 --product=4 \
  --selection-json='{"HDD":"2 ТБ, 5400 rpm","SOFTWARE":["Microsoft Office (Trial)"]}'
```

Explicitly mutating guest-session Basket check:

```bash
php local/modules/kk.korsac/tests/Integration/cart_add_smoke.php --iblock=2 --product=4 \
  --selection-json='{"HDD":"2 ТБ, 5400 rpm","SOFTWARE":["Microsoft Office (Trial)"]}' --confirm-write
```

Without `--confirm-write`, the mutating smoke exits before loading Bitrix or writing data. It never creates an Order.
With confirmation it reloads the saved item from the current FUSER/site Basket and verifies the persisted product, price,
currency, quantity, `CUSTOM_PRICE`, and exact property codes/values. It also reloads the snapshot row and verifies its
key, hash, payload hash, and final unit price against the Cart response; it does not merely re-project in-memory data.
The smoke requires an otherwise empty test FUSER Basket, creates an unsaved Order, invokes `Order::setBasket()`, and verifies
that configured `PRICE`, `BASE_PRICE`, zero discount, custom-price flag, order total, and KORSAC properties survive. It never
saves the Order.

For a separate cold-process gate, take `basketItemId`, `fuserId`, `siteId`, `finalPriceMinor`, and `priceTypeId` from the
write smoke's `coldSmokeInput`, exit that process, and run:

```bash
php local/modules/kk.korsac/tests/Integration/cart_provider_cold_smoke.php \
  --basket-item=<ID> --fuser=<ID> --site=<SITE> --final-price-minor=<MINOR> --price-type=<ID> --cleanup
```

The cold smoke loads only the Bitrix prolog and `sale` explicitly. Provider resolution must load `kk.korsac` from the
persisted Basket `MODULE`; it verifies price metadata and properties through an unsaved `Order::setBasket()` call.

## v1 limitations

The configured unit price is authoritative; this module does not introduce a second coupon/discount engine or order-time revalidation. Cart update, line merging, abandoned-snapshot cleanup, payments, delivery, reservation/stock, compatibility, B2B channel selection, SYSTEM ID, production passport, serials, and QC remain out of scope. SYSTEM ID may reference this immutable commercial snapshot in a later version.
