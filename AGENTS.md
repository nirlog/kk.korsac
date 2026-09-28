# AGENTS.md

## Project

This repository contains the server-side Bitrix D7 module `kk.korsac` for the KORSAC computer brand.

Module ID: `kk.korsac`  
PHP namespace: `KK\Korsac`  
Target PHP: 8.2+  
Target platform: 1C-Bitrix D7

## Before changing code

For PR1, read these files completely before implementation:

1. `docs/tasks/KK-KORSAC-PR1.md`
2. `docs/architecture/KORSAC-HL-TECHNICAL-SPEC.md`

The task document defines scope. The technical specification defines schema semantics.

Do not silently change schema semantics. If a Bitrix limitation prevents an exact implementation, choose the smallest safe deviation and document it in the pull request.

## Architectural boundaries

`kk.korsac` owns domain data and server-side business rules.

It may own:

- KORSAC HL-block schema;
- migrations;
- schema health/self-check;
- component-class repositories;
- physical-SKU registry logic;
- supplier-offer data model;
- validated MINI build profiles;
- future domain validation services.

It must not own presentation concerns.

Do not put into this module:

- site templates;
- CSS/SCSS;
- browser TypeScript/JavaScript;
- product-page markup;
- frontend animations.

Those belong in `nirlog/kk-template`.

## PR1 scope discipline

PR1 is infrastructure only.

Implement only what is required for:

- module skeleton;
- idempotent HL schema installation;
- versioned migrations;
- SQL indexes and UNIQUE constraints;
- schema self-check;
- minimal component-class read repository;
- tests/smoke validation;
- documentation required to operate the above.

Do not add in PR1:

- catalog creation;
- catalog product CRUD;
- configurator;
- basket/order integration;
- price formulas;
- `kk.price-update` integration;
- supplier imports;
- automatic physical-SKU selection;
- universal compatibility rule engine;
- admin CRUD interfaces;
- KORSAC SYSTEM ID UI;
- changes to the current King-Komp project.

## Data model rules

Preserve the layered model:

```text
CATALOG PRODUCT
      ↓
COMPONENT CLASS
      ↓
APPROVED PHYSICAL SKU
      ↓
SUPPLIER OFFER / STOCK
```

Compact systems additionally use:

```text
VALIDATED BUILD PROFILE
```

Do not collapse all component classes into one generic `KorsacComponent` HL block. Separate class HL blocks are intentional.

`UF_XML_ID` is the stable identifier. Cross-HL references in v0.1 use stable string codes rather than numeric row IDs.

Do not make destructive schema changes during normal install/upgrade.

## Bitrix implementation rules

Prefer D7 APIs and Bitrix ORM.

Legacy module installation entry points may be used where Bitrix requires them, but business logic must not be built around legacy global-style APIs when a D7 alternative is appropriate.

Installer/migrations must be idempotent:

- do not create duplicate HL blocks;
- do not create duplicate fields;
- do not create duplicate indexes;
- do not silently mutate incompatible existing fields;
- do not delete production data to resolve conflicts.

If schema and database disagree incompatibly, fail with a clear diagnostic.

Normal module uninstall must preserve KORSAC HL data.

## Schema source of truth

Keep schema metadata centralized. `SchemaDefinition` (or a clearly equivalent single source) must drive installer and self-check logic.

Do not duplicate the same field/index definition in multiple unrelated classes.

## Complexity rules

Keep the first implementation deliberately small.

Do not add:

- DI container;
- CQRS;
- event sourcing;
- custom ORM over Bitrix ORM;
- generic migration framework unrelated to KORSAC needs;
- generic rule engine;
- microservices.

Prefer straightforward services with narrow responsibilities.

## Tests

Test pure PHP logic without requiring a full Bitrix web request where possible.

For Bitrix-dependent installation behavior, provide a small integration/smoke script that can run inside an initialized Bitrix environment.

Do not build a large fake Bitrix framework solely for unit tests.

At minimum PR1 should cover the acceptance criteria in `docs/tasks/KK-KORSAC-PR1.md`.

## Pull requests

Keep pull requests focused.

PR descriptions must state:

- what was implemented;
- created HL blocks;
- migration strategy;
- SQL index/UNIQUE strategy;
- self-check capabilities;
- tests run;
- known limitations;
- manual smoke-test steps;
- any deviations from the technical specification.

Do not mix unrelated refactors into feature PRs.