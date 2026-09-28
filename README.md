# kk.korsac

Server-side domain module for the KORSAC computer brand on 1C-Bitrix D7.

## Purpose

`kk.korsac` owns KORSAC business data and domain rules that must not live in the site template:

- component classes;
- approved physical SKUs;
- supplier offers;
- validated MINI build profiles;
- schema installation and migrations;
- schema health/self-check;
- server-side repositories and validation services.

The frontend/presentation layer lives separately in `nirlog/kk-template`.

## Module identity

- Bitrix module ID: `kk.korsac`
- PHP namespace: `KK\Korsac`
- Target platform: 1C-Bitrix D7
- Target PHP: 8.2+

## Current phase

PR1 is infrastructure only. The first implementation must provide:

1. installable Bitrix module skeleton;
2. idempotent HL-block schema installer;
3. versioned migrations;
4. SQL indexes and UNIQUE constraints;
5. schema self-check;
6. minimal read API for component classes;
7. unit tests for pure PHP logic and a Bitrix integration smoke test.

See:

- `docs/tasks/KK-KORSAC-PR1.md`
- `docs/architecture/KORSAC-HL-TECHNICAL-SPEC.md`
- `AGENTS.md`

## Out of scope for PR1

PR1 must not implement:

- frontend or template code;
- catalog UI;
- product configurator;
- basket/checkout/order integration;
- pricing formulas;
- `kk.price-update` integration;
- supplier API imports;
- automatic physical-SKU selection;
- universal compatibility engine;
- component admin CRUD UI;
- changes to the current King-Komp site or its HL blocks.

## Data model

The core model is:

```text
CATALOG PRODUCT
      ↓
COMPONENT CLASS
      ↓
APPROVED PHYSICAL SKU
      ↓
SUPPLIER OFFER / STOCK
```

For compact systems there is an additional whitelist layer:

```text
VALIDATED BUILD PROFILE
```

The public catalog sells a guaranteed component class. Production resolves that class to an approved physical SKU. The final KORSAC SYSTEM ID records the actually installed SKU.

## Repository workflow

Use small feature branches and pull requests into `main`.

The first implementation task is defined in `docs/tasks/KK-KORSAC-PR1.md`. Do not expand its scope without an explicit decision.