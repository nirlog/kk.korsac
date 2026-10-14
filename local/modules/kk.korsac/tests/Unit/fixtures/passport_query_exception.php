<?php

declare(strict_types=1);

namespace Bitrix\Main\DB;
/** Single driver-reason test double, not a fake database/Bitrix framework. */
class SqlQueryException extends \RuntimeException
{
    public function __construct(private readonly string $databaseReason) { parent::__construct('database operation failed'); }
    public function getDatabaseMessage(): string { return $this->databaseReason; }
}
