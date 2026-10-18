<?php

declare(strict_types=1);

namespace KK\Korsac\MySystems;

/** Explicit account allowlist, independent of internal System/Passport records. */
final readonly class MySystemView
{
    /** @param list<MySystemComponentView> $components */
    public function __construct(public string $systemId,public string $systemType,public string $modelName,public string $lifecycleState,public ?string $transferredAt,public ?string $warrantyStartedAt,public ?string $passportPath,public array $components) {}
    public function toArray(): array
    {
        return ['systemId'=>$this->systemId,'systemType'=>$this->systemType,'modelName'=>$this->modelName,'lifecycleState'=>$this->lifecycleState,'transferredAt'=>$this->transferredAt,'warrantyStartedAt'=>$this->warrantyStartedAt,'passportPath'=>$this->passportPath,'components'=>array_map(static fn(MySystemComponentView $component):array=>$component->toArray(),$this->components)];
    }
}
