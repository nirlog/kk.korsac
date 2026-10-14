<?php
// kk.korsac:public-passport-delivery:v1

declare(strict_types=1);

use KK\Korsac\Passport\PublicPassportHttpView;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true) die();
$view=$arResult['VIEW'];
if (!$view instanceof PublicPassportHttpView) throw new RuntimeException('invalid_public_view');
$escape=static fn(string $text):string=>htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$date=static fn(string $iso):string=>(new DateTimeImmutable($iso))->format('d.m.Y');
$passport=$view->passport;
?>
<main class="kk-korsac-passport" aria-labelledby="korsac-passport-title">
    <p class="kk-korsac-passport__brand">KORSAC</p>
    <h1 id="korsac-passport-title">Паспорт системы</h1>
    <?php if ($view->state==='ACTIVE' && $passport!==null): ?>
        <section class="kk-korsac-passport__identity" aria-label="Система">
            <p class="kk-korsac-passport__id"><?= $escape($passport->systemId) ?></p>
            <h2><?= $escape($passport->modelName) ?></h2>
            <?php $type=match ($passport->systemType) { 'MODEL'=>'Серийная конфигурация', 'CUSTOM'=>'Индивидуальная сборка', default=>null }; ?>
            <?php if ($type!==null): ?><p><?= $escape($type) ?></p><?php endif; ?>
        </section>
        <dl class="kk-korsac-passport__dates">
            <div><dt>Дата передачи</dt><dd><time datetime="<?= $escape($passport->transferredAt) ?>"><?= $escape($date($passport->transferredAt)) ?></time></dd></div>
            <div><dt>Гарантия с</dt><dd><time datetime="<?= $escape($passport->warrantyStartedAt) ?>"><?= $escape($date($passport->warrantyStartedAt)) ?></time></dd></div>
        </dl>
        <section aria-labelledby="korsac-passport-components">
            <h2 id="korsac-passport-components">Комплектация</h2>
            <?php if ($passport->components===[]): ?>
                <p>Данные о комплектующих пока не внесены.</p>
            <?php else: ?>
                <ul class="kk-korsac-passport__components">
                    <?php foreach ($passport->components as $component): ?>
                        <li><span class="kk-korsac-passport__slot"><?= $escape($component->slotLabel) ?></span><span><?= $escape($component->name) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php elseif ($view->state==='NOT_FOUND'): ?>
        <p>Паспорт системы не найден.</p>
    <?php else: ?>
        <p>Паспорт временно недоступен. Попробуйте открыть страницу позже.</p>
    <?php endif; ?>
</main>
