# KORSAC Frontend Configurator Core v1

## Boundary

The core is a storefront-neutral state and API adapter. It renders no markup,
ships no styles, and makes no assumptions about a product-card template. Its
persistent browser state contains only `iblockId`, `productId`, and the current
selection. Price and option projections are returned to subscribers for
rendering but are never retained as authoritative state.

The server remains authoritative. A calculation response is display data only.
`addToCart()` sends identity plus current selection to `kk:korsac.Cart.add`; it
never sends a calculated price, price type, currency, or snapshot content. Cart
continues to validate the configuration, calculate its price, and create its
immutable snapshot server-side.

## Loading in Bitrix

Installation copies the extension to `/local/js/kk/korsac/configurator-core`.
Load and mount it from any temporary or final storefront template:

```php
<?php \Bitrix\Main\UI\Extension::load('kk.korsac.configurator-core'); ?>
```

```javascript
const core = new BX.KK.Korsac.ConfiguratorCore({
    transport: new BX.KK.Korsac.BitrixTransport(BX),
    iblockId: 2,
    productId: 123
});

const unsubscribe = core.subscribe(({type, detail, state}) => {
    // The storefront owns rendering, formatting, accessibility and messages.
    // `detail.price` is a server projection for display, not client authority.
});

core.load();
core.setGroup('RAM', 'RAM_64');
core.calculate();
core.addToCart();
```

`load()` calls `kk:korsac.Configurator.get` with an explicit `method: 'GET'`
and GET parameters; the method option is required because Bitrix otherwise
defaults `BX.ajax.runAction` to POST. `calculate()` and `addToCart()` call their
actions with an explicit `method: 'POST'`. Methods return promises with the
unwrapped Bitrix `data` value. Public errors become
`KorsacConfiguratorApiError` values with `code`, `message`, `customData`, and
`errors`.

## Events and race handling

Subscribers receive `selection`, `loaded`, `calculated`, `added`, and `error`.
An event includes a defensive copy of current state. Unsubscribe with the
function returned by `subscribe()`.

Slow calculation responses cannot overwrite a newer selection or emit stale
display data or errors. Their promises still settle for the original callers,
while only a calculation for the current selection may normalize state and
emit `calculated` or `error`. The backend whitelist remains authoritative even
though the adapter rejects obviously malformed group/value shapes before
transport.

## Test

```bash
node local/modules/kk.korsac/tests/Frontend/run.js
```
