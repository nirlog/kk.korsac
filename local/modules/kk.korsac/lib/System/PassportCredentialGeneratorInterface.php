<?php

declare(strict_types=1);

namespace KK\Korsac\System;
interface PassportCredentialGeneratorInterface
{
    public function generatePublicId(): string;
    public function generateSecretCode(): string;
}
