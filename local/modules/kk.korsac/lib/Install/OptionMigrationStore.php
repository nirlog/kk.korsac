<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use Bitrix\Main\Config\Option;

final class OptionMigrationStore implements MigrationStoreInterface
{
    private const MODULE_ID = 'kk.korsac';
    private const OPTION_NAME = 'applied_migrations';

    public function has(string $migrationId): bool
    {
        return in_array($migrationId, $this->all(), true);
    }

    public function markApplied(string $migrationId): void
    {
        $migrations = $this->all();
        if (!in_array($migrationId, $migrations, true)) {
            $migrations[] = $migrationId;
            Option::set(self::MODULE_ID, self::OPTION_NAME, json_encode($migrations, JSON_THROW_ON_ERROR));
        }
    }

    private function all(): array
    {
        $value = Option::get(self::MODULE_ID, self::OPTION_NAME, '[]');
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
