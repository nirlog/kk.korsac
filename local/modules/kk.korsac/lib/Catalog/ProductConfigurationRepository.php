<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use InvalidArgumentException;
use KK\Korsac\Repository\OptionRepository;

final class ProductConfigurationRepository
{
    public function __construct(
        private readonly CatalogPropertyGatewayInterface $gateway,
        private readonly OptionRepository $options = new OptionRepository(),
    ) {}

    public function get(int $iblockId, int $productId): ProductConfiguration
    {
        if ($iblockId <= 0 || $productId <= 0 || !$this->gateway->iblockExists($iblockId) || !$this->gateway->productExists($iblockId, $productId)) {
            throw new InvalidArgumentException("Product {$productId} does not exist in iblock {$iblockId}");
        }
        $values = [];
        foreach (array_keys(CatalogPropertySchema::properties()) as $code) {
            $values[$code] = $this->gateway->values($iblockId, $productId, $code);
        }
        return ProductConfiguration::fromPropertyValues(
            $values,
            fn(string $group, string $xmlId): ?array => $this->options->findByTypeAndXmlId($group, $xmlId),
        );
    }
}
