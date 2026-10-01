# KORSAC pricing policy v1

## Price meanings

`HlOptionPriceProvider` is the raw `UF_PRICE` boundary. It normalizes the DB decimal to integer minor units and does not apply policy. Raw hardware prices (`CPU`, `GPU`, `MB`, `RAM`, `SSD`, `HDD`, `PSU`, `COOLER`, `CASE`) are procurement costs. Raw `OS`, `SOFTWARE`, and `SERVICE` prices are direct retail contributions. `OptionPricingModeRegistry` is the single authoritative mapping.

`RetailOptionPriceProvider` converts procurement amounts with the configured hardware markup and passes direct-retail amounts through unchanged. Markup is integer-only, in basis points, with deterministic half-up rounding:

```text
retail hardware = procurement + ROUND_HALF_UP(procurement * markupBps / 10000)
retail OS/software/service = raw UF_PRICE
```

Overflow produces `price_overflow`. `ConfigurationPriceCalculator` remains policy-agnostic and computes `retail(selected) - retail(default)`, optional single contributions, and multiple contributions from normalized retail prices. The fixed system adjustment is therefore never part of an option delta.

`DefaultConfigurationCostCalculator` retains its provider-defined component-cost semantics; with `HlOptionPriceProvider` it is raw default cost. `DefaultCatalogPriceCalculator` instead sums retail prices of non-null single defaults (including direct-retail OS), excludes multiple `SOFTWARE` and `SERVICE`, and adds `systemFixedAdjustmentMinor` exactly once. Its result contains groups, `componentsRetailMinor`, the adjustment, and total. It does not write Catalog prices.

## Server-side price context

Public Configurator requests contain only `iblockId`, `productId`, and (for calculate) `selection`. The server fixes the channel to `RETAIL`; the browser cannot provide a channel, price type, markup, or policy. `BUSINESS` is a canonical reserved channel that can be mapped later without changing the API.

Bitrix module options use these deterministic keys:

```text
pricing.price_type.<iblockId>.<channel>
pricing.policy.<iblockId>.<priceTypeId>.markup_bps
pricing.policy.<iblockId>.<priceTypeId>.fixed_adjustment_minor
```

The explicit `(iblock, channel)` mapping resolves a Catalog price type, and `(iblock, priceTypeId)` resolves its policy. Missing data fails as `catalog_price_type_not_configured` or `pricing_policy_not_configured`. There is no fallback to price type 1 and **Bitrix `BASE=Y` is not a KORSAC price-selection rule**. A configured `BASE=N` type is valid. Catalog reads use `PRODUCT_ID` plus the explicit `CATALOG_GROUP_ID`; RUB remains the only supported currency.

```bash
php local/modules/kk.korsac/tools/pricing.php configure \
  --iblock=2 --channel=RETAIL --price-type=1 \
  --markup-bps=2000 --fixed-adjustment-minor=0
php local/modules/kk.korsac/tools/pricing.php show --iblock=2 --channel=RETAIL
php local/modules/kk.korsac/tests/Integration/pricing_policy_smoke.php \
  --iblock=2 --product=4 --channel=RETAIL
```

The CLI verifies that the type exists but does not require `BASE=Y`, writes only KORSAC options, and never updates Catalog or HL prices. The smoke is read-only.

## Transitional consistency warning

This module never writes Catalog prices. Until the follow-up `kk.price-update` change consumes `DefaultCatalogPriceCalculator` and `PricingPolicy`, an existing Catalog price may still have been generated from raw defaults plus the old adjustment while Configurator correctly returns policy-normalized retail deltas. Operators must account for this temporary mismatch; this PR does not modify `kk.price-update` or silently rebuild prices.
