<?php

declare(strict_types=1);

namespace KK\Korsac\MySystems;

/** Account hardware identity only; never an internal component/history record. */
final readonly class MySystemComponentView
{
    public function __construct(public string $typeCode,public string $slotLabel,public string $name) {}
    public function toArray(): array { return ['typeCode'=>$this->typeCode,'slotLabel'=>$this->slotLabel,'name'=>$this->name]; }
}
