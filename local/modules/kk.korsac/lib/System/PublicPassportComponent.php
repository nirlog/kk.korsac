<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final readonly class PublicPassportComponent
{
    public function __construct(public string $typeCode,public string $slotLabel,public string $name) {}
    public function toArray(): array { return ['typeCode'=>$this->typeCode,'slotLabel'=>$this->slotLabel,'name'=>$this->name]; }
}
