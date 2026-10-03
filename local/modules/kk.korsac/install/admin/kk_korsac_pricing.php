<?php

declare(strict_types=1);

$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
foreach (['/local/modules', '/bitrix/modules'] as $moduleHolder) {
    $adminPage = $documentRoot . $moduleHolder . '/kk.korsac/admin/kk_korsac_pricing.php';
    if (is_file($adminPage)) {
        require $adminPage;
        return;
    }
}

throw new RuntimeException('Cannot locate the kk.korsac admin page in a Bitrix module holder.');
