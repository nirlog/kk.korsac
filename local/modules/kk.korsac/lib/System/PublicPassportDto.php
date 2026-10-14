<?php

declare(strict_types=1);

namespace KK\Korsac\System;
final readonly class PublicPassportDto
{
    /** @param list<PublicPassportComponent> $components Explicit public fields only. */
    public function __construct(public string $publicId,public string $systemId,public string $systemType,public string $modelName,public string $transferredAt,public string $warrantyStartedAt,public array $components) {}
    public function toArray(): array
    {
        return ['publicId'=>$this->publicId,'systemId'=>$this->systemId,'systemType'=>$this->systemType,'modelName'=>$this->modelName,'transferredAt'=>$this->transferredAt,'warrantyStartedAt'=>$this->warrantyStartedAt,'components'=>array_map(static fn(PublicPassportComponent $c):array=>$c->toArray(),$this->components)];
    }
}
