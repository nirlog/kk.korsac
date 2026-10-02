<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;
use Bitrix\Sale\Order;
use KK\Korsac\Cart\BasketPropertyProjector;
use KK\Korsac\Cart\BitrixBasketGateway;
use KK\Korsac\Cart\BitrixConfigurationSnapshotRepository;
use KK\Korsac\Cart\BitrixProductViewProvider;
use KK\Korsac\Cart\BitrixSiteResolver;
use KK\Korsac\Cart\ConfigurationSnapshotBuilder;
use KK\Korsac\Cart\ConfiguredProductCartService;
use KK\Korsac\Catalog\BitrixCatalogPropertyGateway;
use KK\Korsac\Catalog\ProductConfigurationRepository;
use KK\Korsac\Configurator\BitrixCatalogPriceProvider;
use KK\Korsac\Configurator\ConfiguredProductPricingService;
use KK\Korsac\Configurator\HlOptionViewProvider;
use KK\Korsac\Pricing\BitrixPricingPolicyProvider;
use KK\Korsac\Pricing\ConfiguredCatalogPriceTypeResolver;
use KK\Korsac\Pricing\HlOptionPriceProvider;
use KK\Korsac\Pricing\PriceNormalizer;
use KK\Korsac\Repository\OptionRepository;

$arguments = getopt('', ['iblock:', 'product:', 'selection-json::', 'confirm-write']);
if (!array_key_exists('confirm-write', $arguments)) {
    fwrite(STDERR, "Refusing to mutate Basket without --confirm-write.\n");
    exit(2);
}
$iblockId = filter_var($arguments['iblock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$productId = filter_var($arguments['product'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
try {
    $selection = json_decode((string)($arguments['selection-json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    if ($iblockId === false || $productId === false || !is_array($selection) || (array_is_list($selection) && $selection !== [])) {
        throw new InvalidArgumentException('Invalid arguments');
    }
} catch (Throwable) {
    fwrite(STDERR, "Usage: php cart_add_smoke.php --iblock=<ID> --product=<ID> --selection-json='<JSON>' --confirm-write\n");
    exit(2);
}

$documentRoot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 5);
$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
require $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
foreach (['iblock', 'highloadblock', 'catalog', 'sale', 'kk.korsac'] as $module) {
    if (!Loader::includeModule($module)) {
        fwrite(STDERR, "Required module {$module} is unavailable.\n");
        exit(2);
    }
}

try {
    $options = new OptionRepository();
    $repository = new BitrixConfigurationSnapshotRepository();
    $siteResolver = new BitrixSiteResolver();
    $projector = new BasketPropertyProjector();
    $pricing = new ConfiguredProductPricingService(
        new ProductConfigurationRepository(new BitrixCatalogPropertyGateway(), $options),
        new BitrixCatalogPriceProvider(),
        new HlOptionPriceProvider($options),
        new ConfiguredCatalogPriceTypeResolver(),
        new BitrixPricingPolicyProvider(),
    );
    $service = new ConfiguredProductCartService(
        $pricing,
        new ConfigurationSnapshotBuilder(new HlOptionViewProvider($options)),
        $repository,
        new BitrixBasketGateway(),
        $projector,
        $siteResolver,
        new BitrixProductViewProvider(),
    );
    $result = $service->add((int)$iblockId, (int)$productId, $selection);

    // Reload from Sale instead of projecting the just-created in-memory values.
    $siteId = $siteResolver->currentSiteId();
    $basket = Basket::loadItemsForFUser(Fuser::getId(), $siteId);
    $basketItem = null;
    foreach ($basket as $candidate) {
        if ((int)$candidate->getId() === $result['basket']['basketItemId']) {
            $basketItem = $candidate;
            break;
        }
    }
    if ($basketItem === null) {
        throw new RuntimeException('Saved Basket item was not found after reload');
    }

    $actualProperties = [];
    foreach ($basketItem->getPropertyCollection() as $property) {
        $fields = $property->getFieldValues();
        $code = (string)($fields['CODE'] ?? '');
        if ($code !== '') {
            $actualProperties[$code] = (string)($fields['VALUE'] ?? '');
        }
    }
    ksort($actualProperties, SORT_STRING);

    $stored = $repository->findByKey($result['snapshot']['key']);
    if ($stored === null) {
        throw new RuntimeException('Stored snapshot was not found');
    }
    $expectedProperties = [];
    foreach ($projector->project($stored) as $property) {
        $expectedProperties[$property['CODE']] = $property['VALUE'];
    }
    ksort($expectedProperties, SORT_STRING);

    $actualPriceMinor = PriceNormalizer::toMinor($basketItem->getField('PRICE'));
    $beforeOrder = [
        'priceMinor' => $actualPriceMinor,
        'basePriceMinor' => PriceNormalizer::toMinor($basketItem->getField('BASE_PRICE')),
        'discountPriceMinor' => PriceNormalizer::toMinor($basketItem->getField('DISCOUNT_PRICE')),
        'customPrice' => (string)$basketItem->getField('CUSTOM_PRICE'),
    ];
    $checks = [
        'productId' => (int)$basketItem->getField('PRODUCT_ID') === (int)$productId,
        'price' => $actualPriceMinor === $result['price']['finalPriceMinor'],
        'currency' => (string)$basketItem->getField('CURRENCY') === $result['price']['currency'],
        'quantity' => (string)$basketItem->getField('QUANTITY') === '1.0000' || (float)$basketItem->getField('QUANTITY') === 1.0,
        'customPrice' => (string)$basketItem->getField('CUSTOM_PRICE') === 'Y',
        'properties' => $actualProperties === $expectedProperties,
        'snapshotKey' => $stored->key === $result['snapshot']['key'],
        'snapshotHash' => $stored->hash === $result['snapshot']['hash'] && hash('sha256', $stored->payload) === $stored->hash,
        'snapshotFinalPrice' => $stored->finalPriceMinor === $result['price']['finalPriceMinor'],
    ];
    $failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
    if ($failed !== []) {
        throw new RuntimeException('Persisted Cart verification failed: ' . implode(', ', $failed));
    }
    if (count($basket) !== 1) {
        throw new RuntimeException('Order refresh smoke requires an otherwise empty test FUSER Basket');
    }

    // Exercise the real provider refresh boundary without saving an Order.
    global $USER;
    $userId = is_object($USER) && method_exists($USER, 'GetID') ? (int)$USER->GetID() : 0;
    $order = Order::create($siteId, $userId);
    $setBasketResult = $order->setBasket($basket);
    if (is_object($setBasketResult) && method_exists($setBasketResult, 'isSuccess') && !$setBasketResult->isSuccess()) {
        throw new RuntimeException('Order::setBasket failed');
    }
    $orderItem = null;
    foreach ($order->getBasket() as $candidate) {
        if ((int)$candidate->getId() === $result['basket']['basketItemId']) {
            $orderItem = $candidate;
            break;
        }
    }
    if ($orderItem === null) throw new RuntimeException('Configured item was not found after Order::setBasket');
    $orderProperties = [];
    foreach ($orderItem->getPropertyCollection() as $property) {
        $fields = $property->getFieldValues();
        $code = (string)($fields['CODE'] ?? '');
        if ($code !== '') $orderProperties[$code] = (string)($fields['VALUE'] ?? '');
    }
    ksort($orderProperties, SORT_STRING);
    $afterOrder = [
        'priceMinor' => PriceNormalizer::toMinor($orderItem->getField('PRICE')),
        'basePriceMinor' => PriceNormalizer::toMinor($orderItem->getField('BASE_PRICE')),
        'discountPriceMinor' => PriceNormalizer::toMinor($orderItem->getField('DISCOUNT_PRICE')),
        'customPrice' => (string)$orderItem->getField('CUSTOM_PRICE'),
        'properties' => $orderProperties,
        'orderPriceMinor' => PriceNormalizer::toMinor($order->getPrice()),
    ];
    $orderChecks = [
        'price' => $afterOrder['priceMinor'] === $result['price']['finalPriceMinor'],
        'basePrice' => $afterOrder['basePriceMinor'] === $result['price']['finalPriceMinor'],
        'discountPrice' => $afterOrder['discountPriceMinor'] === 0,
        'customPrice' => $afterOrder['customPrice'] === 'Y',
        'orderPrice' => $afterOrder['orderPriceMinor'] === $result['price']['finalPriceMinor'],
        'properties' => $afterOrder['properties'] === $actualProperties,
    ];
    $failedOrderChecks = array_keys(array_filter($orderChecks, static fn(bool $passed): bool => !$passed));
    if ($failedOrderChecks !== []) throw new RuntimeException('Order::setBasket verification failed: '.implode(', ',$failedOrderChecks));

    echo json_encode([
        ...$result,
        'persistedBasket' => [
            'basketItemId' => (int)$basketItem->getId(),
            'productId' => (int)$basketItem->getField('PRODUCT_ID'),
            'price' => (string)$basketItem->getField('PRICE'),
            'priceMinor' => $actualPriceMinor,
            'currency' => (string)$basketItem->getField('CURRENCY'),
            'quantity' => (string)$basketItem->getField('QUANTITY'),
            'customPrice' => (string)$basketItem->getField('CUSTOM_PRICE'),
            'properties' => $actualProperties,
        ],
        'persistedSnapshot' => [
            'key' => $stored->key,
            'hash' => $stored->hash,
            'finalPriceMinor' => $stored->finalPriceMinor,
        ],
        'verification' => $checks,
        'beforeOrderSetBasket' => $beforeOrder,
        'afterOrderSetBasket' => $afterOrder,
        'orderSetBasketVerification' => $orderChecks,
        'orderSaved' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Cart add smoke failed: {$error->getMessage()}\n");
    exit(1);
}
