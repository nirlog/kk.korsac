<?php

declare(strict_types=1);

namespace KK\Korsac\MySystems;

final readonly class MySystemsPage
{
    /** @param list<MySystemView> $items */
    public function __construct(public array $items,public bool $hasMore) {}
    public function toArray(): array { return ['items'=>array_map(static fn(MySystemView $item):array=>$item->toArray(),$this->items),'hasMore'=>$this->hasMore]; }
}
