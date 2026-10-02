<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Order;

$arguments = getopt('', ['basket-item:', 'fuser:', 'site:', 'final-price-minor:', 'price-type:', 'cleanup']);
$basketItemId = filter_var($arguments['basket-item'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$fuserId = filter_var($arguments['fuser'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$finalPriceMinor = filter_var($arguments['final-price-minor'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
$priceTypeId = filter_var($arguments['price-type'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$siteId = trim((string)($arguments['site'] ?? ''));
if ($basketItemId === false || $fuserId === false || $finalPriceMinor === false || $priceTypeId === false || $siteId === '') {
    fwrite(STDERR, "Usage: php cart_provider_cold_smoke.php --basket-item=<ID> --fuser=<ID> --site=<ID> --final-price-minor=<MINOR> --price-type=<ID> [--cleanup]\n");
    exit(2);
}

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!Loader::includeModule('sale')) {
    fwrite(STDERR, "Required module sale is unavailable.\n");
    exit(2);
}
// Deliberately do not include kk.korsac or catalog here. The persisted Basket MODULE must load its owner,
// and kk.korsac/include.php must load the CatalogProvider parent dependency.

$toMinor = static function (mixed $value): int {
    $decimal = number_format((float)$value, 2, '.', '');
    [$whole, $fraction] = explode('.', $decimal, 2);
    return ((int)$whole * 100) + (int)$fraction;
};
$findItem = static function (Basket $basket, int $id): mixed {
    foreach ($basket as $item) if ((int)$item->getId() === $id) return $item;
    return null;
};
$properties = static function (object $item): array {
    $values=[];
    foreach ($item->getPropertyCollection() as $property) {
        $fields=$property->getFieldValues();$code=(string)($fields['CODE']??'');
        if ($code!=='') $values[$code]=(string)($fields['VALUE']??'');
    }
    ksort($values,SORT_STRING);return $values;
};

try {
    $basket = Basket::loadItemsForFUser((int)$fuserId, $siteId);
    $item = $findItem($basket, (int)$basketItemId);
    if ($item === null) throw new RuntimeException('Persisted Basket item was not found');
    $beforeProperties = $properties($item);
    if ((string)$item->getField('MODULE') !== 'kk.korsac') throw new RuntimeException('Basket MODULE is not kk.korsac');
    if ((string)$item->getField('PRODUCT_PROVIDER_CLASS') !== 'KK\\Korsac\\Cart\\KorsacCatalogProvider') throw new RuntimeException('Unexpected configured provider');
    $expectedCurrency=(string)$item->getField('CURRENCY');
    global $USER;
    $userId=is_object($USER)&&method_exists($USER,'GetID')?(int)$USER->GetID():0;
    $order=Order::create($siteId,$userId);
    $setResult=$order->setBasket($basket);
    if (is_object($setResult)&&method_exists($setResult,'isSuccess')&&!$setResult->isSuccess()) throw new RuntimeException('Order::setBasket failed');
    $refreshed=$findItem($order->getBasket(),(int)$basketItemId);
    if ($refreshed===null) throw new RuntimeException('Basket item disappeared during provider refresh');
    $afterProperties=$properties($refreshed);
    $checks=[
        'providerResolved'=>class_exists('KK\\Korsac\\Cart\\KorsacCatalogProvider',false),
        'price'=>$toMinor($refreshed->getField('PRICE'))===(int)$finalPriceMinor,
        'basePrice'=>$toMinor($refreshed->getField('BASE_PRICE'))===(int)$finalPriceMinor,
        'discountPrice'=>$toMinor($refreshed->getField('DISCOUNT_PRICE'))===0,
        'customPrice'=>(string)$refreshed->getField('CUSTOM_PRICE')==='Y',
        'priceTypeId'=>(int)$refreshed->getField('PRICE_TYPE_ID')===(int)$priceTypeId,
        'productPriceId'=>$refreshed->getField('PRODUCT_PRICE_ID')===null,
        'currency'=>(string)$refreshed->getField('CURRENCY')===$expectedCurrency,
        'orderPrice'=>$toMinor($order->getPrice())===(int)$finalPriceMinor,
        'properties'=>$afterProperties===$beforeProperties,
    ];
    $failed=array_keys(array_filter($checks,static fn(bool $passed):bool=>!$passed));
    if ($failed!==[]) throw new RuntimeException('Cold provider verification failed: '.implode(', ',$failed));

    $cleaned=false;
    if (array_key_exists('cleanup',$arguments)) {
        $cleanupBasket=Basket::loadItemsForFUser((int)$fuserId,$siteId);$cleanupItem=$findItem($cleanupBasket,(int)$basketItemId);
        if ($cleanupItem!==null) {$cleanupItem->delete();$save=$cleanupBasket->save();$cleaned=$save->isSuccess();}
    }
    echo json_encode(['basketItemId'=>(int)$basketItemId,'fuserId'=>(int)$fuserId,'siteId'=>$siteId,'verification'=>$checks,'orderSaved'=>false,'basketItemCleaned'=>$cleaned],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,"Cold provider smoke failed: {$error->getMessage()}\n");exit(1);
}
