<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Closure;
use Throwable;
final class SecurePassportCredentialGenerator implements PassportCredentialGeneratorInterface
{
    /** Optional random-byte source is a test seam; the factory uses random_bytes directly. */
    public function __construct(private readonly ?Closure $randomBytes=null) {}
    public function generatePublicId(): string { return $this->generate(); }
    public function generateSecretCode(): string { return $this->generate(); }
    private function generate(): string
    {
        try {
            $bytes=$this->randomBytes!==null ? ($this->randomBytes)(16) : random_bytes(16);
            if (!is_string($bytes) || strlen($bytes)!==16) throw new SystemException('system_passport_token_generation_failed');
            return bin2hex($bytes);
        } catch (Throwable) { throw new SystemException('system_passport_token_generation_failed'); }
    }
}
