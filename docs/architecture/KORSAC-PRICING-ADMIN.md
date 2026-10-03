# KORSAC pricing policy admin

## Ownership and storage

The pricing policy and its administration belong to `kk.korsac`. The page does
not introduce another table or a serialized shadow copy: it reads and writes
`Bitrix\Main\Config\Option` with module ID `kk.korsac`, using the keys produced
by `PricingConfiguration`.

Each `RETAIL` or `BUSINESS` channel maps a catalog iblock to one numeric Catalog
price type. An unchecked channel has no `pricing.price_type.<iblock>.<channel>`
mapping and is therefore unconfigured. The runtime
`ConfiguredCatalogPriceTypeResolver` continues to report
`catalog_price_type_not_configured` for it.

## Policy identity and values

A policy is identified by **iblock ID + price type ID**, not by channel. Its two
integer values are stored under the existing `pricing.policy` keys. Consequently,
if RETAIL and BUSINESS select the same price type, they necessarily share the
same markup and fixed adjustment. The form rejects conflicting submitted values
for such a shared policy and explains the relationship next to the controls.

The UI accepts a non-negative percentage with at most two decimal places and
converts the decimal string directly to integer basis points (`12,50` percent is
`1250` bps). It accepts a non-negative RUB amount with at most two decimal places
and converts the string directly to minor units (`1000.50` RUB is `100050`). No
authoritative binary floating-point operation is used.

Removing a channel deletes only its channel-to-price-type mapping. Orphan policy
keys are deliberately retained. This conservative behavior prevents a shared
policy from being deleted when another channel still references it; the mapping
is authoritative, so retained values do not configure a channel by themselves.

## Runtime diagnostics and interoperability

The current-settings block uses `ConfiguredCatalogPriceTypeResolver` and
`BitrixPricingPolicyProvider`, the same runtime readers as storefront pricing.
It displays `Настроено`, `Ошибка конфигурации`, or `Не настроено`, together with
both raw integers and formatted admin values. Catalog and price-type existence
are additionally checked against Bitrix Catalog.

The admin form and `tools/pricing.php` use the same Option keys. A CLI change is
visible on the next admin page load, and a successful admin POST is immediately
visible to `pricing.php show`. Saving uses CSRF validation and POST/Redirect/GET.

## Operation

Open **Services → KORSAC → Ценообразование**, select an actual Catalog product
iblock, configure either channel, and save. Access requires administrator status
or the conservative Bitrix `edit_php` operation. The page explicitly requires
the `catalog`, `iblock`, and `kk.korsac` modules.

On install, the module copies only its `kk_korsac_pricing.php` entry point into
`/bitrix/admin`; uninstall removes that file. Uninstall never deletes Option
pricing settings.
