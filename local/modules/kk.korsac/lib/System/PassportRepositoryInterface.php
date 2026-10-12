<?php

declare(strict_types=1);

namespace KK\Korsac\System;
use Closure;
use DateTimeImmutable;
interface PassportRepositoryInterface
{
    public function transaction(Closure $operation): mixed;
    /** Current locking parent read. Throws system_not_found if no System exists. */
    public function lockSystem(int $systemInternalId): PassportSystemState;
    public function lockBySystem(int $systemInternalId): ?PassportRecord;
    public function findBySystem(int $systemInternalId): ?PassportRecord;
    public function findByPublicId(string $publicId): ?PassportRecord;
    /** May throw only PassportCredentialCollision for identifiable credential-UNIQUE failures. */
    public function create(PassportRecord $passport): PassportRecord;
    /** Conditional first activation only; no arbitrary update or historical rewrite. */
    public function activate(int $systemInternalId,DateTimeImmutable $transferredAt,DateTimeImmutable $updatedAt): PassportRecord;
}
