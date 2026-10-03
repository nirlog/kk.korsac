<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

use KK\Korsac\Repository\OptionRepository;

final class HlOptionViewProvider implements OptionViewProviderInterface
{
    private array $cache = [];

    public function __construct(
        private readonly OptionRepository $repository = new OptionRepository(),
        private readonly OptionImageResolverInterface $images = new BitrixOptionImageResolver(),
    ) {}

    public function get(string $group, string $xmlId): OptionView
    {
        $key = $group . "\0" . $xmlId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $row = $this->repository->findByTypeAndXmlId($group, $xmlId);
        if ($row === null) {
            throw new ConfiguratorException(['code' => 'option_not_found', 'group' => $group, 'xmlId' => $xmlId]);
        }
        $publicName = trim((string)($row['UF_PUBLIC_NAME'] ?? ''));
        $name = $publicName !== '' ? $publicName : trim((string)($row['UF_NAME'] ?? ''));
        $description = trim((string)($row['UF_DESCRIPTION'] ?? ''));
        return $this->cache[$key] = new OptionView($xmlId, $name, $description === '' ? null : $description, $this->images->resolve($row['UF_IMAGE'] ?? null));
    }
}
