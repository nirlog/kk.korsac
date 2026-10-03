<?php

declare(strict_types=1);

namespace KK\Korsac\Catalog;

use KK\Korsac\Configurator\OptionViewProviderInterface;

final class ProductPresentationSelfCheck
{
    public function __construct(
        private readonly ProductPresentationRepository $presentations,
        private readonly ProductConfigurationRepository $configurations,
        private readonly OptionViewProviderInterface $views,
    ) {}

    public function run(int $iblockId, int $productId): array
    {
        $presentation = $this->presentations->get($iblockId, $productId);
        $warnings = $presentation->warnings;
        $configuration = $this->configurations->get($iblockId, $productId)->toArray();
        foreach ($configuration as $group => $definition) {
            if ($presentation->mode($group) !== 'image_buttons') { continue; }
            $ids = $definition['mode'] === 'single'
                ? array_values(array_filter(array_merge([$definition['default']], $definition['options']), 'is_string'))
                : $definition['options'];
            foreach ($ids as $xmlId) {
                if ($this->views->get($group, $xmlId)->image === null) {
                    $warnings[] = ['code'=>'presentation_image_missing', 'group'=>$group, 'xmlId'=>$xmlId];
                }
            }
        }
        return ['ok'=>true, 'iblockId'=>$iblockId, 'productId'=>$productId, 'presentation'=>$presentation->modes, 'errors'=>[], 'warnings'=>$warnings];
    }
}
