<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

use DateTimeImmutable;
use KK\Korsac\System\SystemComponent;

/** Safe internal operator projection. This is not the public Passport component DTO. */
final readonly class ComponentCurrentView
{
    public function __construct(public int $componentId,public string $typeCode,public string $slotKey,public string $slotLabel,public string $name,public DateTimeImmutable $installedAt,public ?DateTimeImmutable $removedAt,public bool $active) {}
    public static function of(SystemComponent $row): self { return new self($row->id,$row->typeCode,$row->slotKey,$row->slotLabel,$row->name,$row->installedAt,$row->removedAt,$row->removedAt===null); }
    public function toArray(): array
    {
        return ['componentId'=>$this->componentId,'typeCode'=>$this->typeCode,'slotKey'=>$this->slotKey,'slotLabel'=>$this->slotLabel,'name'=>$this->name,'installedAt'=>$this->installedAt->format(DATE_ATOM),'removedAt'=>$this->removedAt?->format(DATE_ATOM),'active'=>$this->active];
    }
}
