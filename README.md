# kk.korsac

Bitrix D7 module for KORSAC option directories and domain schema infrastructure. Version 0.4 also ships a storefront-neutral, unstyled browser adapter for the public configuration and Cart actions. Product `*_DEFAULT`, `*_OPTIONS`, and `*_MULTI_OPTIONS` properties refer to simple HL option records. The module owns schema, migrations, health checks, configuration reads, policy-based configured-product quotes in integer minor units, immutable commercial snapshots, and configured Bitrix Basket integration. Payment, delivery, inventory, production passports, and presentation remain outside this repository.

See [`local/modules/kk.korsac/README.md`](local/modules/kk.korsac/README.md), the [v0.2 HL specification](docs/architecture/KORSAC-HL-TECHNICAL-SPEC.md), the [product property convention](docs/architecture/KORSAC-PRODUCT-CONFIGURATION.md), [configuration pricing](docs/architecture/KORSAC-CONFIGURATION-PRICING.md), [default configuration cost](docs/architecture/KORSAC-DEFAULT-CONFIGURATION-COST.md), the [public Configurator API](docs/architecture/KORSAC-CONFIGURATOR-API.md), the [frontend configurator core](docs/architecture/KORSAC-FRONTEND-CONFIGURATOR-CORE.md), and the [Cart snapshot architecture](docs/architecture/KORSAC-CART-SNAPSHOT.md).

Pricing policies can be managed in Bitrix admin at **Services → KORSAC →
Ценообразование**. Storage semantics and CLI interoperability are documented in
the [pricing admin architecture](docs/architecture/KORSAC-PRICING-ADMIN.md).
