# KORSAC configuration pricing

## Architecture and responsibility

```text
Catalog Base Price
       +
ProductConfiguration
       +
ConfigurationSelection
       ↓
ConfigurationPriceCalculator
       ↓
OptionPriceProvider
       ↓
KORSAC HL UF_PRICE
       ↓
ConfigurationPriceResult
```

`ProductConfiguration` is the product-owned whitelist. A raw associative array
is converted to `ConfigurationSelection` before calculation; unknown groups,
wrong value shapes, duplicate multiple values, and options outside the whitelist
are rejected with a structured `ConfigurationPricingException` diagnostic.
Missing single groups select their default and missing multiple groups select an
empty list. An explicit `null` is allowed only when the single group's default is
`null`.

The current Catalog price for the DEFAULT configuration is supplied by the
caller as `basePriceMinor`. The calculator does not read or write Catalog,
integrate with `kk.price-update`, derive the base price from option rows, apply
discounts, or persist its result. Pricing is entirely read-only.

## Money boundary

All domain amounts are `int` RUB minor units (kopecks). `UF_PRICE` remains a
Bitrix `double` with precision 2. `HlOptionPriceProvider` converts that value
once with `PriceNormalizer` and caches the resulting integer by group and XML ID
for the lifetime of the provider. Missing rows and missing, malformed, or
negative prices are errors. No floating-point value enters breakdown or total
arithmetic.

## Formulas

For a single group with a non-null default:

```text
singleDelta = selectedPrice - defaultPrice
```

This permits both upgrades and downgrades without negative option prices. If
selected equals default, the delta is zero. For an optional single:

```text
optionalSingleDelta = selectedPrice - 0
```

When both default and selected are `null`, both prices and delta are zero. For a
multiple group:

```text
multipleDelta = SUM(selected option prices)
```

Totals are:

```text
configurationDelta = SUM(single deltas) + SUM(multiple deltas)
finalPrice = baseProductPrice + configurationDelta
```

A negative base price and a negative final price are rejected. The result lists
every configuration group, including zero-delta groups, and includes default,
selected, per-option prices, and group deltas as applicable.

## Read-only integration smoke

```bash
php local/modules/kk.korsac/tests/Integration/configuration_pricing_smoke.php \\
  --iblock=2 --product=4 --base-price-minor=15000000
```

With no selection, every single group remains at its default and multiple groups
are empty, so the delta must be zero. To exercise alternatives:

```bash
php local/modules/kk.korsac/tests/Integration/configuration_pricing_smoke.php \\
  --iblock=2 --product=4 --base-price-minor=15000000 \\
  --selection-json='{"HDD":"HDD_2TB","SOFTWARE":["SOFTWARE_OFFICE"]}'
```

The required arguments are validated before Bitrix is loaded; missing or invalid
arguments exit with status 2. The script only reads product properties and HL
rows and never changes products, properties, option rows, or Catalog prices.
