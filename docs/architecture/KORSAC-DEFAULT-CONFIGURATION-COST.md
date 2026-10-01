# KORSAC default configuration cost

## Purpose and boundaries

`DEFAULT_COMPONENT_COST` is the sum of the absolute `UF_PRICE` values of the
default options in a product's `ProductConfiguration`. It is a read-only domain
calculation in integer minor units. It does not read or write Bitrix Catalog
prices and does not apply margins, assembly, testing, packaging, delivery,
discounts, or any other pricing policy.

These three amounts are intentionally different:

```text
DEFAULT_COMPONENT_COST
    = sum(default option prices)

DEFAULT_CATALOG_PRICE
    = sum(retail-normalized single defaults) + fixed system adjustment

FINAL_CONFIGURATION_PRICE
    = DEFAULT_CATALOG_PRICE + ConfigurationPriceCalculator retail delta
```

## Calculation

The calculator visits all canonical configuration groups. For each `single`
group (`CPU`, `GPU`, `MB`, `RAM`, `SSD`, `HDD`, `PSU`, `COOLER`, `CASE`, and
`OS`), it requests the price of `default` from `OptionPriceProviderInterface`.
A null default contributes zero and causes no provider call. Alternatives in
`options` are never read or included.

`SOFTWARE` and `SERVICE` are `multiple` groups and have no default. Their
options are never read or included, so each contributes zero. The result still
contains all twelve groups to make the calculation reproducible and
diagnosable. Addition is integer-only and raises `price_overflow` if the total
cannot be represented by a PHP integer. Provider errors are propagated.

```text
                    KORSAC HL UF_PRICE
                           ↓
                 ProductConfiguration
                           ↓
          DefaultConfigurationCostCalculator
                           ↓
              DEFAULT_COMPONENT_COST
                           ↓
                  [future PR8]
                           ↓
                   kk.price-update
                           ↓
                  BASE_CATALOG_PRICE
                           ↓
        ConfigurationPriceCalculator + selection
                           ↓
             FINAL_CONFIGURATION_PRICE
```

The calculator depends only on `OptionPriceProviderInterface`.
`HlOptionPriceProvider` supplies normalized minor-unit values and its existing
request-level cache. The calculator does not query HL blocks, Catalog APIs, or
`kk.price-update` directly and performs no writes.

## Read-only smoke test

In an initialized Bitrix installation:

```bash
php local/modules/kk.korsac/tests/Integration/default_configuration_cost_smoke.php \
  --iblock=2 --product=4
```

Both positive IDs are mandatory and are validated before the Bitrix prolog is
loaded. Missing or invalid arguments exit with status 2. A successful response
contains `cost.groups` and `cost.totalMinor`.
