<?php

declare(strict_types=1);

namespace KK\Korsac\Cart;

final class ConfiguredBasketPriceProjector
{
    public function __construct(private readonly ConfigurationSnapshotRepositoryInterface $snapshots) {}

    public function project(string $snapshotKey, int $productId, string $currency): array
    {
        $snapshot = $this->snapshots->findByKey($snapshotKey);
        if ($snapshot === null) {
            throw new CartException(['code' => 'snapshot_not_found']);
        }
        if ($snapshot->productId !== $productId) {
            throw new CartException(['code' => 'snapshot_product_mismatch']);
        }
        if (!hash_equals($snapshot->hash, hash('sha256', $snapshot->payload)) || $snapshot->currency !== $currency) {
            throw new CartException(['code' => 'snapshot_invalid']);
        }

        $price = MinorUnitFormatter::decimal($snapshot->finalPriceMinor);
        return [
            'BASE_PRICE' => $price,
            'PRICE' => $price,
            'DISCOUNT_PRICE' => '0.00',
            'CUSTOM_PRICE' => 'Y',
            'CURRENCY' => $snapshot->currency,
            'PRICE_TYPE_ID' => $snapshot->priceTypeId,
            'PRODUCT_PRICE_ID' => null,
        ];
    }
}
