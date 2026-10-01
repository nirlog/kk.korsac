<?php
declare(strict_types=1);
namespace KK\Korsac\Configurator;
interface ConfiguredProductPricingServiceInterface
{
    public function quote(int $iblockId, int $productId, array $selection): ConfiguredProductQuote;
}
