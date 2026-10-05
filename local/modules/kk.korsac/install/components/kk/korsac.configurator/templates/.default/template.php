<?php

use Bitrix\Main\Web\Json;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

if (($arResult['VALID'] ?? false) !== true) {
    return;
}

$bootstrapJson = Json::encode(
    $arResult['BOOTSTRAP'],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
?>
<div
    id="<?=htmlspecialcharsbx($arResult['DOM_ID'])?>"
    class="kk-korsac-product-configurator"
    data-korsac-configurator
></div>
<noscript>Для настройки компьютера включите JavaScript.</noscript>
<script>
BX.ready(function () {
    'use strict';
    var config = <?=$bootstrapJson?>;
    var root = document.getElementById(config.domId);
    if (!root || !BX.KK || !BX.KK.Korsac || !BX.KK.Korsac.ConfiguratorRenderer) {
        return;
    }

    try {
        var renderer = new BX.KK.Korsac.ConfiguratorRenderer({
            root: root,
            iblockId: config.iblockId,
            productId: config.productId,
            debounceMs: config.debounceMs
        });
        Promise.resolve(renderer.mount()).catch(function () {
            console.error('[KORSAC] configurator mount failed');
        });
    } catch (error) {
        console.error('[KORSAC] configurator mount failed');
    }
});
</script>
