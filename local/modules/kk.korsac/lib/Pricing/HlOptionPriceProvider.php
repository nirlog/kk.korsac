<?php

declare(strict_types=1);

namespace KK\Korsac\Pricing;

use KK\Korsac\Repository\OptionRepository;

final class HlOptionPriceProvider implements OptionPriceProviderInterface
{
    private array $cache = [];

    public function __construct(private readonly OptionRepository $repository = new OptionRepository()) {}

    public function getPriceMinor(string $group, string $xmlId): int
    {
        $key = $group . "\0" . $xmlId;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $row = $this->repository->findByTypeAndXmlId($group, $xmlId);
        if ($row === null) {
            throw new ConfigurationPricingException(['code' => 'price_option_not_found', 'group' => $group, 'xmlId' => $xmlId]);
        }
        if (!array_key_exists('UF_PRICE', $row)) {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price', 'group' => $group, 'xmlId' => $xmlId]);
        }
        try {
            $price = PriceNormalizer::toMinor($row['UF_PRICE']);
        } catch (ConfigurationPricingException) {
            throw new ConfigurationPricingException(['code' => 'invalid_option_price', 'group' => $group, 'xmlId' => $xmlId]);
        }
        if ($price < 0) {
            throw new ConfigurationPricingException(['code' => 'negative_option_price', 'group' => $group, 'xmlId' => $xmlId]);
        }
        return $this->cache[$key] = $price;
    }
}
