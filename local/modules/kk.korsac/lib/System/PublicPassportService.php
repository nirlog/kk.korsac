<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Throwable;
final class PublicPassportService
{
    public function __construct(private readonly PassportRepositoryInterface $passports,private readonly SystemRepositoryInterface $systems,private readonly SystemComponentRepositoryInterface $components) {}
    public function find(string $publicId): PublicPassportDto
    {
        if (!PassportCredentialShape::token($publicId)) throw new SystemException('system_passport_not_found');
        try {
            $passport=$this->passports->findByPublicId($publicId);
            if ($passport===null || $passport->publicId!==$publicId || $passport->state!=='ACTIVE') throw new SystemException('system_passport_not_found');
            $passport->assertValid();
            $system=$this->systems->findById($passport->systemInternalId);
            if ($system===null) throw new SystemException('system_passport_not_found');
            $visible=[];
            foreach ($this->components->findForSystem($system->id,true) as $component) {
                if ($component->removedAt===null && $component->systemInternalId===$system->id) $visible[]=new PublicPassportComponent($component->typeCode,$component->slotLabel,$component->name);
            }
            return new PublicPassportDto($publicId,$system->systemId,$system->source->systemType,$system->source->modelName,$passport->transferredAt->format(DATE_ATOM),$passport->warrantyStartedAt->format(DATE_ATOM),$visible);
        } catch (Throwable) { throw new SystemException('system_passport_not_found'); }
    }
}
