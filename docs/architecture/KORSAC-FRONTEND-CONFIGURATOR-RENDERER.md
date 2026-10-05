# KORSAC frontend configurator renderer v1

## Responsibility and dependency direction

`kk.korsac.configurator-renderer` is a storefront-neutral DOM view over
`kk.korsac.configurator-core`. The renderer owns semantic controls, transient
loading/error feedback, formatting and responsive neutral styles. It does not
own transport, whitelist validation, pricing, Basket payloads or snapshots.

```text
Configurator API → configurator-core → configurator-renderer → DOM
```

The dependency is one-way: the headless core has no renderer or DOM dependency.
The successful `get` payload is the static presentation model; accepted
`loaded` and `calculated` core events are the only price commit boundaries.
Calculate Promise results are deliberately ignored.

## Public API and lifecycle

The extension exports `BX.KK.Korsac.ConfiguratorRenderer` and the CommonJS
`ConfiguratorRenderer`. Construct it with a DOM `root` plus
`iblockId`/`productId`, or inject an existing `core` for tests or integration:

```javascript
const renderer = new BX.KK.Korsac.ConfiguratorRenderer({
    root: document.querySelector('#korsac-renderer-test'),
    iblockId: 2,
    productId: 367,
    debounceMs: 150,
    locale: 'ru-RU',
    labels: {}
});
```

`mount()` builds the shell, subscribes before `core.load()`, and resolves with
the load result. `addToCart()` delegates exclusively to `core.addToCart()`.
`destroy()` cancels the debounce timer, unsubscribes and removes registered DOM
listeners; repeated destruction is safe.

## Presentation modes

| Business mode | Supported presentation modes |
|---|---|
| `single` | `select`, `text_buttons`, `image_buttons` |
| `multiple` | `select`, `text_buttons`, `image_buttons`, `checkboxes` |

Invalid combinations fall back to `select`. Single controls preserve `null`
only when `allowNull` is true; the empty select value maps back to `null`.
Multiple values are arrays normalized to API choice order. Image controls keep
a textual name and remain selectable when an image is absent or fails. Names
and descriptions use `textContent`, never HTML interpretation.

## Price, loading and races

The renderer formats integer minor units with `Intl.NumberFormat`. It displays
`price.finalPriceMinor`, optional `price.configurationDeltaMinor`, and public
`choice.deltaMinor`; it never sums deltas or computes a final price.

Initial load sets `aria-busy` and disables Add. A selection updates the core
synchronously, refreshes selected states, marks the old price as updating and
disables Add. Calculation begins after a 150 ms default debounce. Only an
accepted core `calculated` event confirms the price and re-enables Add. Rejected
calculate Promises are consumed, while only accepted core `error` events change
visible errors, so stale success or failure cannot overwrite current UI.

Add remains unavailable without a confirmed price and while an add request is
running. Concurrent clicks are ignored. Success does not redirect or update a
mini-cart.

## DOM integration events

The root dispatches native `CustomEvent` hooks with public data:

- `korsac:configurator:loaded`
- `korsac:configurator:selection`
- `korsac:configurator:calculated`
- `korsac:configurator:added`
- `korsac:configurator:error` (safe public code only)

These are integration notifications, not another state store.

## Styling and accessibility

Controls use fieldsets/legends, native selects/checkboxes/buttons, associated
labels, `aria-pressed`, `aria-busy`, status/alert regions and keyboard focus.
Neutral BEM classes use the `kk-korsac-configurator` prefix. Storefront override
variables are `--kk-korsac-bg`, `--kk-korsac-text`, `--kk-korsac-muted`,
`--kk-korsac-border`, `--kk-korsac-accent`, `--kk-korsac-radius`, and
`--kk-korsac-gap`. Wrapping controls and an auto-fit image grid support
viewports from approximately 320 px.

## Existing installation update and browser acceptance

An already-installed development instance must copy the extension without
reinstalling schema:

```bash
cd /home/bitrix/www
php -r '
$_SERVER["DOCUMENT_ROOT"]=getcwd();
require "bitrix/modules/main/include/prolog_before.php";
$m=CModule::CreateModuleObject("kk.korsac");
if (!$m || !$m->InstallFiles()) { exit(1); }
'
```

On a Bitrix page, create a host and load/mount the extension:

```javascript
const host = document.createElement('div');
host.id = 'korsac-renderer-test';
document.body.appendChild(host);

BX.Runtime.loadExtension('kk.korsac.configurator-renderer').then(() => {
    window.korsacRendererTest = new BX.KK.Korsac.ConfiguratorRenderer({
        root: document.querySelector('#korsac-renderer-test'),
        iblockId: 2,
        productId: 367
    });
    return window.korsacRendererTest.mount();
});
```

Verify every returned group/default, CASE image cards, SOFTWARE/SERVICE
checkboxes, signed delta labels and server-confirmed price. Under DevTools
network throttling, rapidly select three alternatives and confirm only the final
selection's price appears. Exercise an `image: null` fixture and confirm the
text control still calculates and adds. Finally, click Add twice and confirm one
request, a disabled in-flight button, success feedback and one
`korsac:configurator:added` event. Fixture IDs are acceptance inputs only and
are not renderer constants.
