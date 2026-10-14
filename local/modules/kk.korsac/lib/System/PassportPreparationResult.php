<?php

declare(strict_types=1);

namespace KK\Korsac\System;
/** Sensitive result: only a newly committed creator may receive secretCode, once. */
final readonly class PassportPreparationResult
{
    public function __construct(public PassportRecord $passport, public bool $created, public ?string $secretCode)
    {
        if ($created!==($secretCode!==null) || ($secretCode!==null && !PassportCredentialShape::token($secretCode))) throw new SystemException('system_passport_state_invalid');
    }
}
