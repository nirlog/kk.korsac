<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use InvalidArgumentException;

final class ProductPresentationRepository
{
    public function __construct(private readonly CatalogPropertyGatewayInterface $gateway) {}

    public function get(int $iblockId, int $productId): ProductPresentation
    {
        if (!$this->gateway->iblockExists($iblockId) || !$this->gateway->productExists($iblockId, $productId)) {
            throw new InvalidArgumentException('Product does not exist in the requested iblock');
        }
        $modes = []; $warnings = [];
        foreach (CatalogPropertySchema::groups() as $group => $_) {
            $stored = $this->gateway->enumValues($iblockId, $productId, ProductPresentationSchema::propertyCode($group))[0] ?? '';
            if ($stored !== '' && !in_array($stored, ProductPresentationSchema::allowedModes($group), true)) {
                $warnings[] = ['code'=>'presentation_mode_invalid', 'group'=>$group, 'mode'=>$stored];
                $stored = '';
            }
            $modes[$group] = $stored !== '' ? $stored : ProductPresentationSchema::DEFAULT_MODE;
        }
        return new ProductPresentation($modes, $warnings);
    }
}
