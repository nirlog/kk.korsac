<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

interface MigrationInterface
{
    public function id(): string;

    public function up(): void;
}
