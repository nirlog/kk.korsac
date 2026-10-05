# KORSAC product configurator component

Дата: 2026-10-05. Версия модуля: 0.8.0.

## Ответственность и зависимости

`kk:korsac.configurator` — небольшой bridge:

```text
bitrix:catalog.element
  -> kk:korsac.configurator
  -> kk.korsac.configurator-renderer
  -> kk.korsac.configurator-core
  -> Configurator API / Cart API
```

Компонент проверяет форму идентификаторов, создаёт host, загружает extension и
вызывает `ConfiguratorRenderer.mount()`. PHP не читает товар, options или price,
не вызывает Configurator API и не работает с Basket. Renderer владеет загрузкой,
выбором, расчётом, пользовательскими ошибками и Add.

## Параметры

| Параметр | Требование |
|---|---|
| `IBLOCK_ID` | обязательное положительное целое число |
| `PRODUCT_ID` | обязательное положительное целое число |
| `DEBOUNCE_MS` | default `150`, нормализуется в `0..2000` |
| `DOM_ID` | необязательный HTML id; иначе генерируется случайный уникальный id |

`367foo`, ноль и отрицательные IDs отклоняются. При невалидных обязательных
параметрах host и JS не выводятся, поэтому AJAX не запускается. DOM id допускает
буквы, цифры, `_` и `-`, начинается с буквы и ограничен 128 символами. Bootstrap
сериализуется JSON с HTML-safe flags. Автоматические IDs позволяют нескольким
instances сосуществовать без singleton; явный `DOM_ID` вызывающая сторона должна
сделать уникальным.

## Установка и обновление

Canonical source: `local/modules/kk.korsac/install/components/kk/korsac.configurator`.
`InstallFiles()` рекурсивно копирует его в `/local/components/kk/korsac.configurator`.
Для установленного модуля достаточно повторить:

```php
$module = new kk_korsac();
$module->InstallFiles();
```

Schema migration не нужна. `UnInstallFiles()` удаляет только этот component;
другие `/local/components/kk/*`, HL schema, данные и snapshots сохраняются.

## Подключение из `catalog.element`

```php
<?php
$APPLICATION->IncludeComponent(
    'kk:korsac.configurator',
    '',
    [
        'IBLOCK_ID' => (int)$arResult['IBLOCK_ID'],
        'PRODUCT_ID' => (int)$arResult['ID'],
        'DEBOUNCE_MS' => 150,
    ],
    false,
    ['HIDE_ICONS' => 'Y']
);
?>
```

Site-specific product template намеренно не изменяется.

## Lifecycle, события и ошибки

`BX.ready` находит собственный root и создаёт локальный renderer. Rejection от
`mount()` перехватывается с diagnostic `[KORSAC] configurator mount failed`;
второй customer error UI не создаётся. Без модуля публичная страница не получает
сломанный JS, а администратор видит component error. Runtime selection, price и
Cart state server-side не кэшируются.

Bridge не проксирует события root: `korsac:configurator:loaded`,
`korsac:configurator:selection`, `korsac:configurator:calculated`,
`korsac:configurator:added`, `korsac:configurator:error` доступны напрямую.

## Ручная приёмка на fixture #367

Временно создать, но не коммитить в document root:

```php
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
$APPLICATION->SetTitle('KORSAC configurator test');
$APPLICATION->IncludeComponent('kk:korsac.configurator', '', [
    'IBLOCK_ID' => 2,
    'PRODUCT_ID' => 367,
    'DEBOUNCE_MS' => 150,
]);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
```

Проверить автоматический render всех групп и server price без Console bootstrap;
CASE `image_buttons`, SOFTWARE/SERVICE checkboxes, GPU/SSD deltas и отсутствие
console errors. После подтверждённого расчёта Add должен отправить ровно один
`Cart.add`, создать configured Basket item и snapshot и выпустить `added` event.

> В реальной карточке KORSAC-configurable products должны использовать renderer
> Add flow, а не одновременно стандартную unconfigured кнопку Bitrix
> Add-to-Basket. Замена кнопки относится к следующему PR в `kk-template`.
