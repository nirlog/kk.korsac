<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use RuntimeException;

/** Migration-only operator diagnostic: count, never owner/customer/credential details. */
final class OwnershipLegacyAssignmentsException extends RuntimeException
{
    public function __construct(public readonly int $count) { parent::__construct('ownership_legacy_user_assignments_present'); }
    public function diagnostic(): array { return ['code'=>$this->getMessage(),'count'=>$this->count]; }
}
