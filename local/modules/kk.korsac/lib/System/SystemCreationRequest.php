<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final readonly class SystemCreationRequest
{
    private function __construct(
        public string $siteId, public int $orderId, public int $orderBasketId,
        public int $orderUnitIndex, public string $systemType, public string $modelName,
        public ?int $iblockId, public ?int $productId, public ?string $snapshotKey,
    ) {}

    public static function fromArray(array $input): self
    {
        $allowed = ['SITE_ID','ORDER_ID','ORDER_BASKET_ID','ORDER_UNIT_INDEX','SYSTEM_TYPE','MODEL_NAME','IBLOCK_ID','PRODUCT_ID','CONFIG_SNAPSHOT_KEY'];
        if (array_diff(array_keys($input), $allowed)) throw new SystemException('system_source_invalid');
        foreach (['ORDER_ID','ORDER_BASKET_ID','ORDER_UNIT_INDEX'] as $key) {
            if (!isset($input[$key]) || !is_int($input[$key]) || $input[$key] < 1 || $input[$key] > 2147483647) {
                throw new SystemException('system_source_invalid');
            }
        }
        foreach (['SITE_ID' => 10, 'MODEL_NAME' => 255] as $key => $limit) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === '' || mb_strlen($value, 'UTF-8') > $limit || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
                throw new SystemException('system_source_invalid');
            }
        }
        if (!in_array($input['SYSTEM_TYPE'] ?? null, ['MODEL','CUSTOM'], true)) throw new SystemException('system_type_invalid');
        $iblock = $input['IBLOCK_ID'] ?? null;
        $product = $input['PRODUCT_ID'] ?? null;
        if (($iblock === null) !== ($product === null)) throw new SystemException('system_source_invalid');
        foreach ([$iblock, $product] as $value) {
            if ($value !== null && (!is_int($value) || $value < 1 || $value > 2147483647)) throw new SystemException('system_source_invalid');
        }
        $key = $input['CONFIG_SNAPSHOT_KEY'] ?? null;
        if ($key !== null && (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/D', $key))) throw new SystemException('system_source_invalid');
        return new self($input['SITE_ID'], $input['ORDER_ID'], $input['ORDER_BASKET_ID'], $input['ORDER_UNIT_INDEX'], $input['SYSTEM_TYPE'], $input['MODEL_NAME'], $iblock, $product, $key);
    }
}
