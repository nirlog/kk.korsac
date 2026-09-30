# kk.korsac

Server-side Bitrix D7 module for KORSAC option directories and domain schema infrastructure. Version 0.2 uses product-controlled configuration: product `*_DEFAULT`, `*_OPTIONS`, and `*_MULTI_OPTIONS` properties refer to simple HL option records. The module owns schema, migrations, health checks, and a minimal read repository; presentation, configurator, pricing, basket, and order behavior remain outside this repository.

See [`local/modules/kk.korsac/README.md`](local/modules/kk.korsac/README.md), the [v0.2 HL specification](docs/architecture/KORSAC-HL-TECHNICAL-SPEC.md), and the [product property convention](docs/architecture/KORSAC-PRODUCT-CONFIGURATION.md).
