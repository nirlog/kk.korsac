# KORSAC product presentation v1

## Separation of responsibilities

`ProductConfiguration` defines what can be selected; `ProductPresentation` independently defines how each group is presented. Presentation never changes selection validation, pricing, basket data, or immutable commercial snapshots. No renderer is included in this module.

## Option images

Every option HL directory has optional, single-value `file` field `UF_IMAGE`. The field is installed on new schemas and added to existing schemas by migration `2026_10_03_005_option_image`. Public option projection resolves it through the Bitrix file API and exposes only:

```json
{"image":{"src":"/upload/example.jpg","width":800,"height":800}}
```

An empty or stale file reference produces `"image": null`; it never blocks configuration or purchase.

## Product properties and modes

Each canonical group has a single, optional list property `KK_<GROUP>_VIEW`. Stored domain values are enum `XML_ID`s, never Russian display labels. `select` is both the list default and runtime fallback.

| Business mode | Allowed presentation modes |
|---|---|
| single | `select`, `text_buttons`, `image_buttons` |
| multiple | `select`, `text_buttons`, `image_buttons`, `checkboxes` |

Thus `checkboxes` is restricted to SOFTWARE and SERVICE. An empty, missing, or invalid stored value safely resolves to `select`; invalid values remain visible as `presentation_mode_invalid` diagnostics.

## Public API

`Configurator.get` adds `presentation: {"mode":"..."}` to every group and `image` (object or null) to every choice. `Configurator.calculate` remains limited to normalized selection and authoritative pricing.

## Installation and checks

`tools/catalog.php install-properties --iblock=<ID>` installs both the 22 configuration directory properties and 12 presentation list properties. `check-properties` validates property shape, exact enum XML IDs/labels, and the `select` default. `check-presentation --iblock=<ID> --product=<ID>` reports resolved modes and content warnings. An `image_buttons` option without a resolvable image yields `presentation_image_missing` without making `ok` false.
