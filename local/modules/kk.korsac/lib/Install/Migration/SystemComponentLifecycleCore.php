<?php

declare(strict_types=1);

namespace KK\Korsac\Install\Migration;

use KK\Korsac\Health\SystemComponentDataHealth;
use KK\Korsac\Install\MigrationInterface;
use KK\Korsac\System\SystemException;

/** No DDL or repair: adopt valid physical history before marking lifecycle authority. */
final class SystemComponentLifecycleCore implements MigrationInterface
{
    public const ID='2026_10_16_010_system_component_lifecycle_core';
    public function __construct(private readonly SystemComponentDataHealth $health) {}
    public function id(): string { return self::ID; }
    public function up(): void
    {
        $result=$this->health->run();
        if (!$result['ok']) throw new SystemException($result['errors'][0]['code']==='system_component_read_failed' ? 'system_component_read_failed' : 'system_component_legacy_state_invalid');
    }
}
