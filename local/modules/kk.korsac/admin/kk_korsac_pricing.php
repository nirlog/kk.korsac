<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use KK\Korsac\Admin\BitrixPricingAdminGateway;
use KK\Korsac\Admin\DecimalParser;
use KK\Korsac\Admin\PricingConfigurationAdminService;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $APPLICATION, $USER;
if (!is_object($USER) || (!$USER->IsAdmin() && !$USER->CanDoOperation('edit_php'))) {
    $APPLICATION->AuthForm('Недостаточно прав для изменения настроек KORSAC.');
}

$APPLICATION->SetTitle('KORSAC: Ценообразование');
$loadError = null;
foreach (['catalog', 'iblock', 'kk.korsac'] as $requiredModule) {
    if (!Loader::includeModule($requiredModule)) {
        $loadError = 'Не удалось загрузить обязательный модуль: ' . $requiredModule;
        break;
    }
}

$service = $loadError === null ? new PricingConfigurationAdminService(new BitrixPricingAdminGateway()) : null;
$catalogs = $service?->catalogs() ?? [];
$priceTypes = $service?->priceTypes() ?? [];
$iblockId = filter_var($_REQUEST['iblock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$iblockId = $iblockId === false ? null : (int)$iblockId;
$error = $loadError;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service !== null) {
    if (!check_bitrix_sessid()) {
        $error = 'Сессия истекла. Обновите страницу и повторите сохранение.';
    } elseif ($iblockId === null) {
        $error = 'Выберите каталог.';
    } else {
        try {
            $service->save($iblockId, is_array($_POST['channels'] ?? null) ? $_POST['channels'] : []);
            LocalRedirect('kk_korsac_pricing.php?lang=' . urlencode((string)LANGUAGE_ID) . '&iblock=' . $iblockId . '&saved=Y');
        } catch (Throwable $exception) {
            $error = 'Настройки не сохранены: ' . $exception->getMessage();
        }
    }
}

$view = null;
if ($service !== null && $iblockId !== null && $error === null) {
    try {
        $view = $service->view($iblockId);
    } catch (Throwable $exception) {
        $error = 'Невозможно прочитать настройки: ' . $exception->getMessage();
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($error !== null) {
    CAdminMessage::ShowMessage(htmlspecialcharsbx($error));
}
if (($_GET['saved'] ?? '') === 'Y') {
    CAdminMessage::ShowNote('Настройки сохранены');
}
?>
<form method="get" action="kk_korsac_pricing.php">
    <input type="hidden" name="lang" value="<?=htmlspecialcharsbx((string)LANGUAGE_ID)?>">
    <label for="kk-catalog"><strong>Каталог:</strong></label>
    <select id="kk-catalog" name="iblock">
        <option value="">— Выберите каталог —</option>
        <?php foreach ($catalogs as $id => $name): ?>
            <option value="<?=$id?>"<?=$iblockId === $id ? ' selected' : ''?>>[<?=$id?>] <?=htmlspecialcharsbx($name)?></option>
        <?php endforeach; ?>
    </select>
    <input type="submit" value="Показать" class="adm-btn">
</form>

<?php if ($view !== null): ?>
<form method="post" action="kk_korsac_pricing.php?lang=<?=urlencode((string)LANGUAGE_ID)?>&amp;iblock=<?=$iblockId?>">
    <?=bitrix_sessid_post()?>
    <p><em>Политика идентифицируется каталогом и типом цены. Каналы с одинаковым типом цены используют общие наценку и корректировку.</em></p>
    <?php
    $statusLabels = ['configured' => 'Настроено', 'error' => 'Ошибка конфигурации', 'unconfigured' => 'Не настроено'];
    foreach (PricingConfigurationAdminService::CHANNELS as $channel):
        $item = $view[$channel];
        $markup = $item['markupBasisPoints'] === null ? '0.00' : DecimalParser::formatScaled($item['markupBasisPoints']);
        $fixed = $item['fixedAdjustmentMinor'] === null ? '0.00' : DecimalParser::formatScaled($item['fixedAdjustmentMinor']);
    ?>
        <div class="adm-detail-block" style="margin-top: 18px">
            <div class="adm-detail-title"><?=htmlspecialcharsbx($channel)?></div>
            <div class="adm-detail-content-wrap"><div class="adm-detail-content">
                <p><strong>Статус:</strong> <?=$statusLabels[$item['status']]?></p>
                <?php if ($item['enabled'] && $item['markupBasisPoints'] !== null): ?>
                    <p>Тип цены: [<?=$item['priceTypeId']?>] <?=htmlspecialcharsbx($priceTypes[$item['priceTypeId']] ?? 'удалён')?>;
                    Markup: <?=$item['markupBasisPoints']?> bps (<?=$markup?>%);
                    Fixed: <?=$item['fixedAdjustmentMinor']?> minor (<?=$fixed?> ₽)</p>
                <?php endif; ?>
                <table class="adm-detail-content-table edit-table"><tbody>
                    <tr><td width="40%">Канал настроен:</td><td><input type="checkbox" name="channels[<?=$channel?>][enabled]" value="Y"<?=$item['enabled'] ? ' checked' : ''?>></td></tr>
                    <tr><td>Тип цены:</td><td><select name="channels[<?=$channel?>][priceTypeId]"><option value="">— Не настроено —</option>
                        <?php foreach ($priceTypes as $priceTypeId => $priceTypeName): ?><option value="<?=$priceTypeId?>"<?=$item['priceTypeId'] === $priceTypeId ? ' selected' : ''?>>[<?=$priceTypeId?>] <?=htmlspecialcharsbx($priceTypeName)?></option><?php endforeach; ?>
                    </select></td></tr>
                    <tr><td>Наценка на hardware:</td><td><input type="text" name="channels[<?=$channel?>][markupPercent]" value="<?=$markup?>" size="12"> %</td></tr>
                    <tr><td>Фиксированная корректировка:</td><td><input type="text" name="channels[<?=$channel?>][fixedRub]" value="<?=$fixed?>" size="12"> ₽</td></tr>
                </tbody></table>
            </div></div>
        </div>
    <?php endforeach; ?>
    <div class="adm-detail-content-btns-wrap"><div class="adm-detail-content-btns"><input type="submit" value="Сохранить" class="adm-btn-save"></div></div>
</form>
<?php endif; ?>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
