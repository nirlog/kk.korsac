<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final readonly class OrderBasketLine
{
    /** @param array<string,list<mixed>> $properties Preserve duplicate property values for validation. */
    public function __construct(public int $basketId,public string $module,public int $productId,public string $name,public mixed $quantity,public array $properties) {}
    public function isConfiguredPc(): bool { return $this->module==='kk.korsac' && in_array('Y',$this->properties['KORSAC_CONFIGURED'] ?? [],true); }
}
